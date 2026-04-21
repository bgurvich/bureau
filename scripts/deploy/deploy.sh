#!/usr/bin/env bash
# =============================================================================
# Laravel — Production deploy
# Usage: bash scripts/deploy/deploy.sh [--skip-build] [--force|-f]
# Run from the repo root as the user that owns the checkout (typical after
# install.sh: whoever ran it — e.g. `moshe` — owns the repo and can pull).
# Reads APP_URL / DB_* from .env; auto-detects the php-fpm systemd unit.
# No hardcoded app-specific values, so any Laravel app can use this as-is.
# =============================================================================
# Flow:
#   1. git pull (exit early if no changes)
#   2. Security checks (.env, APP_ENV=production, APP_DEBUG=false, APP_KEY)
#   3. Maintenance mode on
#   4. composer install --no-dev --optimize-autoloader
#   5. npm ci && npm run build (unless --skip-build)
#   6. DB backup → storage/backups/pre-migrate-<timestamp>.sql.gz
#   7. php artisan migrate --force
#   8. php artisan optimize (config + routes + views + events)
#   9. Reload PHP-FPM to clear OPcache
#  10. php artisan queue:restart (graceful worker drain)
#  11. composer audit + npm audit (warn-only)
#  12. Maintenance mode off
#  13. Health check — GET APP_URL/up
# =============================================================================
set -euo pipefail

usage() {
    sed -n '3,22p' "$0" | sed 's/^# //'
    exit 0
}
[[ "${1:-}" == "-h" || "${1:-}" == "--help" ]] && usage

SKIP_BUILD=false
FORCE=false
for arg in "$@"; do
    [[ "$arg" == "--skip-build" ]] && SKIP_BUILD=true
    [[ "$arg" == "--force" || "$arg" == "-f" ]] && FORCE=true
done

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
APP_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$APP_DIR"

export NVM_DIR="${NVM_DIR:-/opt/nvm}"
[ -s "$NVM_DIR/nvm.sh" ] && source "$NVM_DIR/nvm.sh"

RED='\033[0;31m'; YELLOW='\033[1;33m'; GREEN='\033[0;32m'; BOLD='\033[1m'; RESET='\033[0m'
ok()   { echo -e "  ${GREEN}✓${RESET}  $*"; }
warn() { echo -e "  ${YELLOW}!${RESET}  $*"; }
fail() { echo -e "  ${RED}✗${RESET}  $*"; ERRORS=$((ERRORS + 1)); }

# ── Git pull ─────────────────────────────────────────────
echo ""
echo -e "${BOLD}── Git pull ─────────────────────────────────────────────${RESET}"

PULL_OUTPUT=$(git pull 2>&1)
echo "$PULL_OUTPUT"
if [[ "$PULL_OUTPUT" == "Already up to date." ]]; then
    if [[ "$FORCE" == true ]]; then
        echo "No new commits, but --force was set — running the full deploy anyway."
    else
        echo "No changes to deploy. (Use --force to deploy anyway, e.g. after .env edits or a bad previous deploy.)"
        exit 0
    fi
fi

# ── Security checks ──────────────────────────────────────
ERRORS=0
echo ""
echo -e "${BOLD}── Security checks ──────────────────────────────────────${RESET}"

# Parse a value from .env, handling optional quotes and inline comments.
# Returns empty string when the key is absent.
env_value() {
    local key="$1"
    awk -F= -v k="$key" '
        $0 ~ "^"k"=" {
            # Strip the key and the first =
            sub("^"k"=", "", $0)
            # Strip trailing inline comment
            sub(/ +#.*$/, "", $0)
            # Strip matching surrounding quotes
            gsub(/^"|"$|^'\''|'\''$/, "", $0)
            print
            exit
        }
    ' .env
}

if [ ! -f .env ]; then
    fail ".env file not found — copy .env.example and fill it in"
else
    ok ".env exists"
    APP_ENV=$(env_value APP_ENV)
    APP_DEBUG=$(env_value APP_DEBUG)
    APP_KEY=$(env_value APP_KEY)

    [ "$APP_ENV" = "production" ] && ok "APP_ENV=production" \
        || fail "APP_ENV must be 'production' (currently: '${APP_ENV:-<empty>}')"
    [ "$APP_DEBUG" = "false" ] && ok "APP_DEBUG=false" \
        || fail "APP_DEBUG must be 'false' (currently: '${APP_DEBUG:-<empty>}')"
    [ -n "$APP_KEY" ] && ok "APP_KEY is set" \
        || fail "APP_KEY is empty — run: php artisan key:generate"
    # Catch stale checkouts where APP_KEY is still the .env.example placeholder.
    [ "$APP_KEY" = "base64:generate-me" ] && fail "APP_KEY is the example placeholder — regenerate with: php artisan key:generate" || true
fi

[ -w "storage" ]           && ok "storage/ is writable"          || fail "storage/ is not writable"
[ -w "bootstrap/cache" ]   && ok "bootstrap/cache/ is writable"  || fail "bootstrap/cache/ is not writable"
grep -rq 'phpinfo()' public/ 2>/dev/null && fail "phpinfo() found in public/" || true

# public/storage must be a symlink pointing at storage/app/public. A regular
# directory there typically means someone ran `storage:link` on a Windows
# filesystem and then copied files, or a previous deploy failed mid-link —
# either way, continuing would serve the wrong content.
if [ -e public/storage ] && [ ! -L public/storage ]; then
    fail "public/storage exists but is not a symlink — rm it and re-run storage:link"
else
    ok "public/storage is a symlink (or absent)"
fi

# `.env` must not be tracked. A committed .env leaks APP_KEY/DB creds on push.
if git ls-files --error-unmatch .env >/dev/null 2>&1; then
    fail ".env is tracked by git — remove with: git rm --cached .env && git commit"
else
    ok ".env is not tracked by git"
fi

ENV_PERMS=$(stat -c "%a" .env 2>/dev/null || stat -f "%Lp" .env 2>/dev/null)
# Fail (not warn) on world/group-readable perms in production — this file
# carries DB creds, APP_KEY, OAuth secrets. Accept 600 (owner only) or 640
# (owner rw, group r — the common www-data pattern).
case "$ENV_PERMS" in
    600|640)
        ok ".env permissions OK (${ENV_PERMS})"
        ;;
    *)
        fail ".env permissions are ${ENV_PERMS} — run: chmod 640 .env"
        ;;
esac

if [ "$ERRORS" -gt 0 ]; then
    echo ""
    echo -e "${RED}Aborting — fix the $ERRORS error(s) above before releasing.${RESET}"
    exit 1
fi

# ── Maintenance mode ─────────────────────────────────────
echo ""
echo -e "${BOLD}── Maintenance mode ─────────────────────────────────────${RESET}"
php artisan down --retry=60 --refresh=15
ok "Maintenance mode enabled"

cleanup() {
    php artisan up 2>/dev/null || true
}
trap cleanup ERR

# ── Dependencies ─────────────────────────────────────────
echo ""
echo -e "${BOLD}── Dependencies ─────────────────────────────────────────${RESET}"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
ok "composer install --no-dev --optimize-autoloader"

# ── Assets ───────────────────────────────────────────────
if [[ "$SKIP_BUILD" == false ]]; then
    echo ""
    echo -e "${BOLD}── Assets ───────────────────────────────────────────────${RESET}"
    NODE_OPTIONS=--max-old-space-size=512 npm ci --silent
    ok "npm ci"
    NODE_OPTIONS=--max-old-space-size=512 npm run build --silent
    ok "npm run build"
fi

# ── Database backup ──────────────────────────────────────
echo ""
echo -e "${BOLD}── Database backup ──────────────────────────────────────${RESET}"
DB_NAME=$(env_value DB_DATABASE)
DB_USER=$(env_value DB_USERNAME)
DB_PASS=$(env_value DB_PASSWORD)

if [[ -n "$DB_NAME" && -n "$DB_USER" ]]; then
    # Lock the backup dir before writing the dump — SQL backups contain every
    # row the app holds (including encrypted credentials) and must not be
    # readable by other OS users. Enforce before the file lands.
    mkdir -p storage/backups
    chmod 700 storage/backups
    BACKUP="storage/backups/pre-migrate-$(date +%Y%m%d%H%M%S).sql.gz"
    # set -o pipefail is already active so mysqldump failures exit this pipe
    # non-zero even when gzip succeeds on a partial stream.
    if MYSQL_PWD="$DB_PASS" mysqldump --single-transaction --quick -u"$DB_USER" "$DB_NAME" 2>/dev/null | gzip > "$BACKUP"; then
        chmod 600 "$BACKUP"
        # Sanity check the gzip stream so we don't ship forward with a truncated archive.
        if gzip -t "$BACKUP" 2>/dev/null; then
            ok "DB backup: $BACKUP (gzip verified)"
        else
            rm -f "$BACKUP"
            fail "DB backup gzip-test failed — refusing to proceed"
        fi
    else
        rm -f "$BACKUP"
        fail "DB backup failed — refusing to proceed (fix mysqldump before retrying)"
    fi
else
    warn "DB credentials not found in .env — skipping backup"
fi

# ── Database migration ───────────────────────────────────
echo ""
echo -e "${BOLD}── Database ─────────────────────────────────────────────${RESET}"
php artisan migrate --force
ok "migrations applied"

# ── Cache & optimisation ─────────────────────────────────
echo ""
echo -e "${BOLD}── Cache & optimisation ─────────────────────────────────${RESET}"
# Flush every compiled artefact before rebuilding. Without this, a new
# composer dep (class added since the last cache) or a new Blade view
# path can silently 500 because the cached bootstrap file still points
# at the old class map — and the failure happens before Laravel's
# logger initialises, so nothing lands in storage/logs/laravel.log.
php artisan optimize:clear
ok "php artisan optimize:clear (flushed config + route + view + event + cache)"
php artisan optimize
ok "php artisan optimize (config + routes + views + events cached)"

# Scope the post-optimize chmod to the two trees `php artisan optimize`
# actually writes to. `storage/app/` is runtime user data (uploads,
# backups, livewire-tmp) — those subdirs are created by www-data with
# perms the deploy user can't traverse, so a blanket `find storage` trips
# over "Permission denied" before it gets anywhere useful. Filter to
# files we own too, because chmod requires ownership and runtime-created
# files under storage/framework belong to www-data.
find storage/framework bootstrap/cache -user "$(id -un)" -exec chmod ug+rwX,o-rwx {} +
ok "storage/framework + bootstrap/cache → ug+rwX,o-rwx (scoped to files we own)"

# ── Restart services ─────────────────────────────────────
echo ""
echo -e "${BOLD}── Restart services ─────────────────────────────────────${RESET}"
# reload needs root; use `sudo -n` so it fails loudly instead of prompting
# for a password mid-deploy. install.sh installs a /etc/sudoers.d rule that
# grants the deploy user passwordless reload for exactly these services.
if command -v systemctl &>/dev/null; then
    PHP_FPM_UNIT=$(systemctl list-units --type=service --no-pager --plain \
        | awk '/php.*fpm/ {print $1}' | head -1)
    if [ -n "$PHP_FPM_UNIT" ]; then
        if sudo -n systemctl reload "$PHP_FPM_UNIT" 2>/dev/null; then
            ok "Reloaded $PHP_FPM_UNIT"
        else
            warn "Could not reload $PHP_FPM_UNIT — add a sudoers rule: \"$(id -un) ALL=(root) NOPASSWD: /bin/systemctl reload $PHP_FPM_UNIT\""
        fi
    else
        warn "No php-fpm systemd unit found"
    fi
fi

# Graceful queue-worker drain so OcrMedia jobs pick up new code.
php artisan queue:restart
ok "queue:restart (workers drain and respawn)"

# ── Dependency audit ─────────────────────────────────────
echo ""
echo -e "${BOLD}── Dependency audit ─────────────────────────────────────${RESET}"
composer audit 2>&1 && ok "composer audit clean" || warn "composer audit found issues"
npm audit --omit=dev 2>&1 && ok "npm audit clean" || warn "npm audit found issues"

# ── Up + health check ────────────────────────────────────
echo ""
echo -e "${BOLD}── Exiting maintenance mode ─────────────────────────────${RESET}"
php artisan up
trap - ERR
ok "Maintenance mode disabled"

echo ""
echo -e "${BOLD}── Health check ─────────────────────────────────────────${RESET}"
APP_URL=$(env_value APP_URL)
if [[ -n "$APP_URL" ]]; then
    if curl -fsS --max-time 10 "${APP_URL}/up" > /dev/null 2>&1; then
        ok "App is up at ${APP_URL}/up"
    else
        warn "Health check failed — ${APP_URL}/up did not respond"
    fi
else
    warn "APP_URL not set — skipping health check"
fi

echo ""
echo -e "${GREEN}${BOLD}Release complete.${RESET}"
echo ""

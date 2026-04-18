#!/usr/bin/env bash
# =============================================================================
# Bureau — Production deploy
# Usage: bash scripts/deploy/deploy.sh [--skip-build]
# Run from the repo root as the web-server user (or via sudo -u www-data).
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
for arg in "$@"; do
    [[ "$arg" == "--skip-build" ]] && SKIP_BUILD=true
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
    echo "No changes to deploy."
    exit 0
fi

# ── Security checks ──────────────────────────────────────
ERRORS=0
echo ""
echo -e "${BOLD}── Security checks ──────────────────────────────────────${RESET}"

if [ ! -f .env ]; then
    fail ".env file not found — copy .env.example and fill it in"
else
    ok ".env exists"
    APP_ENV=$(grep -E '^APP_ENV=' .env | cut -d= -f2 | tr -d '"' | tr -d "'")
    APP_DEBUG=$(grep -E '^APP_DEBUG=' .env | cut -d= -f2 | tr -d '"' | tr -d "'")
    APP_KEY=$(grep -E '^APP_KEY=' .env | cut -d= -f2 | tr -d '"' | tr -d "'")

    [ "$APP_ENV" = "production" ] && ok "APP_ENV=production" \
        || fail "APP_ENV must be 'production' (currently: '${APP_ENV:-<empty>}')"
    [ "$APP_DEBUG" = "false" ] && ok "APP_DEBUG=false" \
        || fail "APP_DEBUG must be 'false' (currently: '${APP_DEBUG:-<empty>}')"
    [ -n "$APP_KEY" ] && ok "APP_KEY is set" \
        || fail "APP_KEY is empty — run: php artisan key:generate"
fi

[ -w "storage" ]           && ok "storage/ is writable"          || fail "storage/ is not writable"
[ -w "bootstrap/cache" ]   && ok "bootstrap/cache/ is writable"  || fail "bootstrap/cache/ is not writable"
grep -rq 'phpinfo()' public/ 2>/dev/null && fail "phpinfo() found in public/" || true

ENV_PERMS=$(stat -c "%a" .env 2>/dev/null || stat -f "%Lp" .env 2>/dev/null)
if [ "${ENV_PERMS: -1}" = "0" ]; then
    ok ".env is not world-readable (${ENV_PERMS})"
else
    warn ".env permissions are ${ENV_PERMS} — consider: chmod 640 .env"
fi

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
DB_NAME=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2 | tr -d '"' | tr -d "'" | xargs 2>/dev/null || true)
DB_USER=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2 | tr -d '"' | tr -d "'" | xargs 2>/dev/null || true)
DB_PASS=$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2 | tr -d '"' | tr -d "'" | xargs 2>/dev/null || true)

if [[ -n "$DB_NAME" && -n "$DB_USER" ]]; then
    mkdir -p storage/backups
    BACKUP="storage/backups/pre-migrate-$(date +%Y%m%d%H%M%S).sql.gz"
    MYSQL_PWD="$DB_PASS" mysqldump -u"$DB_USER" "$DB_NAME" 2>/dev/null | gzip > "$BACKUP" \
        && ok "DB backup: $BACKUP" \
        || warn "DB backup failed — proceeding anyway"
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
php artisan optimize
ok "php artisan optimize (config + routes + views + events cached)"

chmod -R ug+rw storage bootstrap/cache
ok "storage/ + bootstrap/cache/ → ug+rw"

# ── Restart services ─────────────────────────────────────
echo ""
echo -e "${BOLD}── Restart services ─────────────────────────────────────${RESET}"
if command -v systemctl &>/dev/null; then
    PHP_FPM_UNIT=$(systemctl list-units --type=service --no-pager --plain \
        | awk '/php.*fpm/ {print $1}' | head -1)
    if [ -n "$PHP_FPM_UNIT" ]; then
        systemctl reload "$PHP_FPM_UNIT" 2>/dev/null \
            && ok "Reloaded $PHP_FPM_UNIT" \
            || warn "Could not reload $PHP_FPM_UNIT — reload manually"
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
APP_URL=$(grep -E '^APP_URL=' .env | cut -d= -f2 | tr -d '"' | tr -d "'" | xargs 2>/dev/null || true)
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

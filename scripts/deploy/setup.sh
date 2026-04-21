#!/usr/bin/env bash
# =============================================================================
# Bureau — First-time app setup (local dev or fresh deploy target)
# Usage: bash scripts/deploy/setup.sh
# Run from the repo root.
# =============================================================================
# Idempotent. Safe to re-run.
#   1. Check prerequisites (php, composer, node, mariadb client)
#   2. Copy .env.example → .env if missing
#   3. php artisan key:generate when APP_KEY is empty
#   4. composer install
#   5. npm ci + npm run build
#   6. Ensure storage/ + bootstrap/cache/ writable
#   7. php artisan storage:link
#   8. php artisan migrate --force
#   9. php artisan db:seed --force  (only on fresh DB)
#  10. Verify tesseract + imagemagick present (warn if missing)
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
APP_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$APP_DIR"

export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
[ -s "$NVM_DIR/nvm.sh" ] && source "$NVM_DIR/nvm.sh"

RED='\033[0;31m'; YELLOW='\033[1;33m'; GREEN='\033[0;32m'; BOLD='\033[1m'; RESET='\033[0m'
ok()   { echo -e "  ${GREEN}✓${RESET}  $*"; }
warn() { echo -e "  ${YELLOW}!${RESET}  $*"; }
fail() { echo -e "  ${RED}✗${RESET}  $*"; exit 1; }

echo -e "${BOLD}── Bureau setup ─────────────────────────────────────────${RESET}"

# ── Prerequisites ────────────────────────────────────────
for bin in php composer node npm; do
    command -v "$bin" >/dev/null 2>&1 && ok "$bin: $($bin --version 2>&1 | head -1)" \
        || fail "$bin not found — run sudo bash scripts/deploy/install-packages.sh first"
done

# ── .env ─────────────────────────────────────────────────
if [ ! -f .env ]; then
    cp .env.example .env
    ok "Copied .env.example → .env"
    warn "Review .env for DB credentials, APP_URL, mail settings"
    warn "Production: run 'sudo bash scripts/deploy/install.sh' next (env + nginx + certbot)"
else
    ok ".env exists"
fi
# Lock .env to the owner only. It carries APP_KEY, DB creds, OAuth secrets —
# world-readability on a shared server is a credential leak.
chmod 600 .env
ok ".env → 0600 (owner-only)"

# Generate APP_KEY if empty
if ! grep -qE '^APP_KEY=.+$' .env || grep -qE '^APP_KEY=$' .env; then
    php artisan key:generate --force
    ok "APP_KEY generated"
else
    ok "APP_KEY present"
fi

# ── Composer ─────────────────────────────────────────────
composer install --no-interaction
ok "composer install"

# ── NPM + build ──────────────────────────────────────────
npm ci --silent
ok "npm ci"

npm run build --silent
ok "npm run build"

# ── Permissions ──────────────────────────────────────────
mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache
# ug+rw adds the bits we need; o-rwx revokes world-access so session files,
# cached views, and any stray backup aren't readable by other OS users.
chmod -R ug+rwX,o-rwx storage bootstrap/cache
ok "storage/ + bootstrap/cache/ → ug+rwX,o-rwx (world-unreadable)"

# ── Storage link ─────────────────────────────────────────
if [ ! -L public/storage ]; then
    php artisan storage:link
    ok "storage:link"
else
    ok "public/storage already linked"
fi

# ── Database ─────────────────────────────────────────────
php artisan migrate --force
ok "migrations applied"

# Seed the default household + owner user + starter/system categories on a
# fresh DB. Demo rows (accounts, transactions, etc.) are opt-in via a
# separate seeder — run `php artisan db:seed --class=DemoDataSeeder` in dev
# if you want them.
USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | tail -1 | tr -d '[:space:]')
if [[ "${USER_COUNT:-0}" == "0" ]]; then
    php artisan db:seed --force
    ok "database seeded (household + owner user + starter categories)"
else
    ok "database has $USER_COUNT user(s) — skipping seed"
fi

# ── Optional toolchain checks ────────────────────────────
command -v tesseract >/dev/null 2>&1 && ok "tesseract: $(tesseract --version 2>&1 | head -1)" \
    || warn "tesseract not found — OCR pipeline will mark media as 'failed'"
command -v convert >/dev/null 2>&1 && ok "imagemagick: $(convert --version | head -1)" \
    || warn "imagemagick not found — icon + media utilities may degrade"

echo ""
echo -e "${GREEN}${BOLD}Setup complete.${RESET}"
echo ""
echo -e "Next:"
echo -e "  ${BOLD}composer dev${RESET}  — start dev server + queue + vite + pail"
echo ""

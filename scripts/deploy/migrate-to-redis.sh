#!/usr/bin/env bash
# =============================================================================
# Bureau — Migrate existing .env to Redis for session / cache / queue
#
# Use this on boxes that were provisioned when the installer defaulted to the
# database drivers (or any .env that's been editing against the older
# defaults). Idempotent: re-running after already-Redis .env is a no-op.
#
# Usage:
#   bash scripts/deploy/migrate-to-redis.sh [APP_DIR]
#
# Side effects on an authenticated install:
#   - Active sessions are invalidated (sessions moved from DB to Redis).
#     Users get bounced to the login page on their next request.
#   - Queued jobs in the `jobs` table stay put; future dispatches go to Redis.
#     Process any stragglers with `php artisan queue:work --queue=default`
#     against the database connection before cutover if that matters.
# =============================================================================
set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}[info]${RESET}  $*"; }
success() { echo -e "${GREEN}[ok]${RESET}    $*"; }
warn()    { echo -e "${YELLOW}[warn]${RESET}  $*"; }
die()     { echo -e "${RED}[error]${RESET} $*" >&2; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
APP_DIR="${1:-$(cd "${SCRIPT_DIR}/../.." && pwd)}"
ENV_FILE="${APP_DIR}/.env"

[[ -f "$ENV_FILE" ]] || die ".env not found at ${ENV_FILE}"

# Same safe rewrite shape as install.sh's env_set — no sed on secrets.
env_set() {
    local key="$1" value="$2"
    local tmp
    tmp="$(mktemp)"
    chmod --reference="$ENV_FILE" "$tmp" 2>/dev/null || chmod 600 "$tmp"
    local line found=0
    while IFS= read -r line || [[ -n "$line" ]]; do
        if [[ "$line" == "${key}="* ]]; then
            printf '%s=%s\n' "$key" "$value" >> "$tmp"
            found=1
        else
            printf '%s\n' "$line" >> "$tmp"
        fi
    done < "$ENV_FILE"
    (( found )) || printf '%s=%s\n' "$key" "$value" >> "$tmp"
    # Preserve ownership / mode of the existing .env.
    chown --reference="$ENV_FILE" "$tmp" 2>/dev/null || true
    mv "$tmp" "$ENV_FILE"
}

echo -e "${BOLD}── Migrate .env to Redis ──${RESET}  (${ENV_FILE})"

env_set SESSION_DRIVER         "redis"
env_set SESSION_CONNECTION     "session"
env_set CACHE_STORE            "redis"
env_set QUEUE_CONNECTION       "redis"
env_set REDIS_CLIENT           "phpredis"
env_set REDIS_HOST             "127.0.0.1"
env_set REDIS_PORT             "6379"
env_set REDIS_PASSWORD         "null"
env_set REDIS_DB               "0"
env_set REDIS_CACHE_DB         "1"
env_set REDIS_QUEUE_DB         "2"
env_set REDIS_SESSION_DB       "3"
env_set REDIS_QUEUE_CONNECTION "queue"

success "Redis driver keys written to .env."

if command -v redis-cli >/dev/null 2>&1; then
    if redis-cli -h 127.0.0.1 ping 2>/dev/null | grep -q PONG; then
        success "redis-cli PING → PONG ($(redis-cli -h 127.0.0.1 info server 2>/dev/null | grep redis_version: | tr -d '\r'))"
    else
        warn "redis-cli installed but 127.0.0.1:6379 didn't answer. Install/start redis-server."
    fi
else
    warn "redis-cli not found — is redis-server installed? (sudo apt-get install redis-server)"
fi

# Flush compiled config cache so PHP-FPM picks up the new drivers without a
# reboot. If php-fpm or queue:restart aren't available (e.g. laptop dev),
# skip silently.
cd "$APP_DIR"

if [[ -f bootstrap/cache/config.php ]]; then
    php artisan config:clear >/dev/null 2>&1 || true
    info "Cleared config cache."
fi

if command -v systemctl >/dev/null 2>&1; then
    PHP_FPM_UNIT="$(systemctl list-units --type=service --no-pager --plain 2>/dev/null \
        | awk '/php.*fpm/ {print $1}' | head -1)"
    if [[ -n "$PHP_FPM_UNIT" ]]; then
        sudo systemctl reload "$PHP_FPM_UNIT" 2>/dev/null \
            && success "Reloaded $PHP_FPM_UNIT (PHP config reread)." \
            || warn "Could not reload $PHP_FPM_UNIT — reload manually."
    fi
fi

php artisan queue:restart >/dev/null 2>&1 \
    && success "queue:restart broadcast — workers will respawn against Redis." \
    || warn "queue:restart failed — restart workers manually."

echo
warn "All active login sessions have been invalidated; users will be signed out on next request."
echo

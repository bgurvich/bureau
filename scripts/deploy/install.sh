#!/usr/bin/env bash
# =============================================================================
# Bureau — Unified installer
#
# Runnable by any user with sudo. Handles packages, database, app user, .env,
# composer, frontend build, artisan, nginx, TLS, queue worker, scheduler.
# Adapted from ~/nfp/scripts/deploy/install.sh but collapsed into a single
# entrypoint — install-packages.sh + setup.sh are superseded by this file.
#
# Usage:
#   bash scripts/deploy/install.sh                        # run all steps
#   bash scripts/deploy/install.sh --only nginx ssl       # subset
#   bash scripts/deploy/install.sh --skip firewall        # all except
#   bash scripts/deploy/install.sh --force nginx          # re-run one step
#   bash scripts/deploy/install.sh --force all            # re-run everything
#
# Available steps (run in this order):
#   packages      OS packages (PHP, MariaDB, nginx, Redis, Tesseract,
#                 ImageMagick, Poppler, p7zip, Certbot, Node via NVM).
#   mariadb       Create `bureau` DB + user.
#   app-user      Create `bureau` OS user.
#   permissions   chown repo → bureau:www-data, normalise modes.
#   env           Write .env with DB / mail / backup creds.
#   composer      composer install as `bureau`.
#   frontend      npm ci + npm run build as `bureau`.
#   artisan       artisan migrate + db:seed (on empty DB).
#   storage-link  artisan storage:link.
#   nginx         vhost template → /etc/nginx/sites-available/bureau.conf.
#   ssl           Let's Encrypt cert + renewal hooks.
#   queue-worker  bureau-queue.service systemd unit.
#   scheduler     `* * * * * artisan schedule:run` crontab for `bureau`.
#   firewall      UFW: allow SSH + Nginx Full. (skipped by default — use --only firewall.)
#
# Completion markers tracked in /var/lib/bureau-install/. Safe to re-run.
# =============================================================================
set -euo pipefail
umask 077
export DEBIAN_FRONTEND=noninteractive

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

info()    { echo -e "${CYAN}[info]${RESET}  $*"; }
success() { echo -e "${GREEN}[ok]${RESET}    $*"; }
warn()    { echo -e "${YELLOW}[warn]${RESET}  $*"; }
die()     { echo -e "${RED}[error]${RESET} $*" >&2; exit 1; }
step()    { echo -e "\n${BOLD}━━━  $*  ━━━${RESET}"; }

[[ $EUID -eq 0 ]] && die "Run as a normal user with sudo, not as root. The script escalates where needed."
command -v sudo >/dev/null 2>&1 || die "sudo is required; install sudo (or run as a user with sudo) and re-run."

# Keep the sudo credential warm for the length of the run.
sudo -v || die "sudo authentication failed."
( while true; do sudo -nv 2>/dev/null; sleep 60; done ) &
SUDO_KEEPALIVE_PID=$!
trap '[[ -n "${SUDO_KEEPALIVE_PID:-}" ]] && kill "$SUDO_KEEPALIVE_PID" 2>/dev/null || true' EXIT

# ── Flags ─────────────────────────────────────────────────────────────────────
ONLY_STEPS=()
SKIP_STEPS=()
FORCE_STEPS=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        --only|--skip|--force|--redo)
            local_flag="$1"; shift
            while [[ $# -gt 0 && "$1" != --* ]]; do
                case "$local_flag" in
                    --only)          ONLY_STEPS+=("$1")  ;;
                    --skip)          SKIP_STEPS+=("$1")  ;;
                    --force|--redo)  FORCE_STEPS+=("$1") ;;
                esac
                shift
            done
            continue
            ;;
        -h|--help)
            sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
    esac
    shift
done

# ── Completion markers ───────────────────────────────────────────────────────
MARK_DIR="/var/lib/bureau-install"
sudo mkdir -p "$MARK_DIR"
sudo chown "$USER":"$USER" "$MARK_DIR"

already_done() { [[ -f "${MARK_DIR}/$1.done" ]]; }
mark_done()    { date -u +%FT%TZ > "${MARK_DIR}/$1.done"; }
mark_clear()   { rm -f "${MARK_DIR}/$1.done"; }

is_forced() {
    local name="$1"
    [[ " ${FORCE_STEPS[*]} " == *" all "* ]] && return 0
    [[ " ${FORCE_STEPS[*]} " == *" $name "* ]]
}

should_run() {
    local name="$1"
    # certbot is an alias for ssl
    [[ "$name" == "certbot" ]] && name="ssl"
    [[ " ${SKIP_STEPS[*]} " == *" $name "* ]] && return 1
    [[ " ${SKIP_STEPS[*]} " == *" certbot "* && "$name" == "ssl" ]] && return 1

    # firewall is opt-in: it's only run when explicitly --only firewall (or
    # when no --only filter and it's not --skip'd — but by default it IS on
    # the default list; skip gate makes it explicit).
    if [[ "$name" == "firewall" && ${#ONLY_STEPS[@]} -eq 0 ]]; then
        return 1
    fi

    # `--force X Y` (without "all") narrows the run to those steps only.
    if [[ ${#FORCE_STEPS[@]} -gt 0 ]] \
        && [[ " ${FORCE_STEPS[*]} " != *" all "* ]] \
        && [[ ${#ONLY_STEPS[@]} -eq 0 ]]; then
        [[ " ${FORCE_STEPS[*]} " == *" $name "* ]] || return 1
    fi

    [[ ${#ONLY_STEPS[@]} -eq 0 ]] || [[ " ${ONLY_STEPS[*]} " == *" $name "* ]] \
        || [[ " ${ONLY_STEPS[*]} " == *" certbot "* && "$name" == "ssl" ]]
}

run_step() {
    local name="$1" fn="$2"
    should_run "$name" || return 0
    if already_done "$name" && ! is_forced "$name"; then
        info "skip ${name} — already completed $(cat "${MARK_DIR}/${name}.done")"
        return 0
    fi
    is_forced "$name" && mark_clear "$name"
    "$fn"
    mark_done "$name"
}

# ── Configuration ────────────────────────────────────────────────────────────
_script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_detected_app_dir="$(cd "${_script_dir}/../.." && pwd)"
if [[ -f "${_detected_app_dir}/artisan" ]]; then
    APP_DIR="${APP_DIR:-$_detected_app_dir}"
else
    APP_DIR="${APP_DIR:-$(pwd)}"
fi
APP_USER="${APP_USER:-bureau}"
DOMAIN="${DOMAIN:-bureau.homes}"
PHP_VER="${PHP_VER:-8.3}"
NODE_VER="${NODE_VER:-22}"
NVM_DIR="${NVM_DIR:-/opt/nvm}"
DB_PASSWORD="${DB_PASSWORD:-}"
MAIL_HOST="${MAIL_HOST:-}"
MAIL_PORT="${MAIL_PORT:-}"
MAIL_USER="${MAIL_USER:-}"
MAIL_PASS="${MAIL_PASS:-}"
ADMIN_EMAIL="${ADMIN_EMAIL:-}"
BACKUP_ARCHIVE_PASSWORD="${BACKUP_ARCHIVE_PASSWORD:-}"

step "Configuration"

prompt() {
    local var="$1" label="$2" default="$3" secret="${4:-}"
    if [[ -n "${!var:-}" ]]; then return; fi
    if [[ "$secret" == "secret" ]]; then
        read -rsp "  ${label} [default: ${default:-none}]: " val; echo
    else
        read -rp  "  ${label} [default: ${default}]: " val
    fi
    printf -v "$var" '%s' "${val:-$default}"
}

# Read / set .env values safely. env_set rewrites the file without running
# sed on it (secrets don't leak into `ps`, and sed metachars don't corrupt).
env_read() {
    local key="$1" env="${APP_DIR}/.env"
    sudo test -f "$env" || return 0
    sudo awk -F= -v k="$key" '
        $0 ~ "^"k"=" {
            sub("^"k"=", "", $0)
            sub(/ +#.*$/, "", $0)
            gsub(/^"|"$|^'\''|'\''$/, "", $0)
            print
            exit
        }
    ' "$env"
}

env_set() {
    local key="$1" value="$2" env="${APP_DIR}/.env"
    sudo test -f "$env" || { warn "env_set: ${env} missing"; return 1; }
    local tmp
    tmp="$(mktemp)"
    chmod 600 "$tmp"
    sudo cat "$env" | {
        local line found=0
        while IFS= read -r line || [[ -n "$line" ]]; do
            if [[ "$line" == "${key}="* ]]; then
                printf '%s=%s\n' "$key" "$value" >> "$tmp"
                found=1
            else
                printf '%s\n' "$line" >> "$tmp"
            fi
        done
        (( found )) || printf '%s=%s\n' "$key" "$value" >> "$tmp"
    }
    sudo install -m 640 -o "$APP_USER" -g www-data "$tmp" "$env"
    rm -f "$tmp"
}

# APP_DIR and DOMAIN are needed for almost every step — collect once up-front.
# Per-step data (DB password, mail creds, admin email, backup password) is
# prompted lazily inside the step that needs it so `--only nginx` doesn't ask
# for the DB password it'll never use.
prompt APP_DIR "Application directory" "$APP_DIR"
prompt DOMAIN  "Domain"                 "$DOMAIN"

# Lazy prompters — first call prompts, subsequent calls return the cached value
# so two steps needing the same input only ask once.
ensure_db_password() {
    if [[ -z "$DB_PASSWORD" ]]; then
        local default
        default="$(env_read DB_PASSWORD)"
        [[ -n "$default" ]] || default="$(openssl rand -base64 18)"
        prompt DB_PASSWORD "MariaDB password for 'bureau' user" "$default" secret
    fi
}
ensure_mail_config() {
    if [[ -z "$MAIL_HOST" && -z "${_MAIL_PROMPTED:-}" ]]; then
        prompt MAIL_HOST "SMTP host (blank → mail log driver)" ""
        prompt MAIL_PORT "SMTP port"                           "587"
        prompt MAIL_USER "SMTP username"                       "noreply@${DOMAIN}"
        prompt MAIL_PASS "SMTP password"                       "" secret
        _MAIL_PROMPTED=1
    fi
}
ensure_backup_password() {
    if [[ -z "$BACKUP_ARCHIVE_PASSWORD" ]]; then
        local default
        default="$(env_read BACKUP_ARCHIVE_PASSWORD)"
        [[ -n "$default" ]] || default="$(openssl rand -base64 32 | tr -d '/=+' | head -c 40)"
        prompt BACKUP_ARCHIVE_PASSWORD "Backup archive encryption password" "$default" secret
    fi
}
ensure_admin_email() {
    if [[ -z "$ADMIN_EMAIL" ]]; then
        prompt ADMIN_EMAIL "Admin / Let's Encrypt email" "admin@${DOMAIN}"
    fi
}

echo
info "App dir:  $APP_DIR"
info "Domain:   $DOMAIN"
info "App user: $APP_USER"
[[ ${#ONLY_STEPS[@]} -gt 0 ]] && info "Only:     ${ONLY_STEPS[*]}" \
    || { [[ ${#SKIP_STEPS[@]} -gt 0 ]] && info "Skip:     ${SKIP_STEPS[*]}" || info "Steps:    default set"; }
echo
read -rp "  Proceed? (y/N) " confirm
[[ "$confirm" =~ ^[Yy]$ ]] || die "Aborted."

# =============================================================================
# STEPS
# =============================================================================

step_packages() {
    step "System packages"

    sudo dpkg --configure -a 2>/dev/null || true
    sudo apt-get update
    sudo apt-get install -y --no-install-recommends \
        git unzip curl wget ca-certificates gnupg2 lsb-release \
        software-properties-common apt-transport-https openssl

    if ! dpkg -l | grep -q "^ii  php${PHP_VER}-cli"; then
        sudo add-apt-repository -y ppa:ondrej/php
        sudo apt-get update
    fi

    # Laravel stack.
    sudo apt-get install -y --no-install-recommends \
        nginx mariadb-server redis-server composer \
        "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-mysql" \
        "php${PHP_VER}-redis" "php${PHP_VER}-mbstring" "php${PHP_VER}-xml" \
        "php${PHP_VER}-curl" "php${PHP_VER}-zip" "php${PHP_VER}-bcmath" \
        "php${PHP_VER}-intl" "php${PHP_VER}-gd" "php${PHP_VER}-opcache" \
        certbot

    # OCR + media + backup toolchain. ImageMagick's default policy blocks
    # the PDF coder (ImageTragick mitigation, CVE-2016-3714); PDFs go
    # through Poppler's pdftotext / pdftoppm instead. p7zip-full unpacks
    # AES-encrypted backup archives (unzip can't).
    sudo apt-get install -y --no-install-recommends \
        tesseract-ocr tesseract-ocr-eng \
        imagemagick poppler-utils p7zip-full

    # Node via NVM — system-wide at /opt/nvm, symlinked into /usr/local/bin.
    if [[ ! -d "$NVM_DIR" ]]; then
        sudo mkdir -p "$NVM_DIR"
        sudo git clone --quiet --depth=1 https://github.com/nvm-sh/nvm.git "$NVM_DIR"
    fi
    # Run nvm inside a sudo shell so the install lands system-wide.
    sudo bash -c "
        export NVM_DIR='${NVM_DIR}'
        source '${NVM_DIR}/nvm.sh'
        nvm install '${NODE_VER}' >/dev/null
        nvm alias default '${NODE_VER}' >/dev/null
        node_bin=\"\$(nvm which '${NODE_VER}')\"
        ln -sf \"\$node_bin\"                      /usr/local/bin/node
        ln -sf \"\$(dirname \"\$node_bin\")/npm\"  /usr/local/bin/npm
        ln -sf \"\$(dirname \"\$node_bin\")/npx\"  /usr/local/bin/npx
    "

    sudo systemctl enable --now mariadb
    sudo systemctl enable --now redis-server
    sudo systemctl enable --now "php${PHP_VER}-fpm"
    sudo systemctl enable --now nginx

    success "Packages installed."
    info "Versions:"
    printf "  PHP:       %s\n" "$(php -v | head -1)"
    printf "  Composer:  %s\n" "$(composer --version 2>/dev/null | head -1)"
    printf "  Node:      %s\n" "$(node -v 2>/dev/null)"
    printf "  MariaDB:   %s\n" "$(mariadb --version)"
    printf "  Tesseract: %s\n" "$(tesseract --version 2>&1 | head -1)"
    printf "  Poppler:   %s\n" "$(pdftotext -v 2>&1 | head -1 | awk '{print $3}')"
}

step_mariadb() {
    step "MariaDB — database and user"
    ensure_db_password

    # Escape single quotes in DB_PASSWORD (SQL string literal).
    local esc_pw="${DB_PASSWORD//\'/\'\'}"

    sudo mariadb <<SQL
CREATE DATABASE IF NOT EXISTS bureau CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'bureau'@'localhost' IDENTIFIED BY '${esc_pw}';
ALTER USER 'bureau'@'localhost' IDENTIFIED BY '${esc_pw}';
GRANT ALL PRIVILEGES ON bureau.* TO 'bureau'@'localhost';
FLUSH PRIVILEGES;
SQL

    # If .env already exists, keep it in sync so a re-run of `mariadb` alone
    # doesn't leave the app pointing at a stale password.
    if sudo test -f "${APP_DIR}/.env"; then
        env_set DB_CONNECTION mariadb
        env_set DB_HOST       127.0.0.1
        env_set DB_PORT       3306
        env_set DB_DATABASE   bureau
        env_set DB_USERNAME   bureau
        env_set DB_PASSWORD   "$DB_PASSWORD"
    fi

    success "MariaDB: database 'bureau' and user 'bureau'@localhost ready."
}

step_app_user() {
    step "App user"
    if ! id "$APP_USER" &>/dev/null; then
        sudo useradd -m -s /bin/bash -U "$APP_USER"
        success "User '${APP_USER}' created."
    else
        info "User '${APP_USER}' already exists."
    fi
}

step_permissions() {
    step "File permissions"
    [[ -d "$APP_DIR" ]] || die "APP_DIR '$APP_DIR' does not exist."

    # Ancestor traverse — nginx / php-fpm runs as www-data and needs +x on
    # every directory above APP_DIR to reach public/index.php. Home directories
    # default to 0700 (no traverse for anyone but the owner), which blocks
    # that path. Grant o+x up the chain from APP_DIR to / so others can
    # traverse (not list — just enter). Stops at /.
    local parent="$APP_DIR"
    while [[ "$parent" != "/" && -n "$parent" ]]; do
        sudo chmod o+x "$parent" 2>/dev/null || true
        parent="$(dirname "$parent")"
    done

    sudo chown -R "${APP_USER}:www-data" "$APP_DIR"
    # ug+rwX everywhere, o-rwx. Storage + bootstrap/cache also group-writable
    # so the www-data FPM process can write cached views / logs.
    sudo find "$APP_DIR" -type d -exec chmod 2755 {} +
    sudo find "$APP_DIR" -type f -exec chmod 644 {} +
    [[ -f "${APP_DIR}/artisan" ]] && sudo chmod +x "${APP_DIR}/artisan"
    [[ -d "${APP_DIR}/vendor/bin" ]] && sudo chmod -R +x "${APP_DIR}/vendor/bin" 2>/dev/null || true
    [[ -d "${APP_DIR}/node_modules/.bin" ]] && sudo chmod -R +x "${APP_DIR}/node_modules/.bin" 2>/dev/null || true
    [[ -d "${APP_DIR}/scripts/deploy" ]] && sudo chmod +x "${APP_DIR}/scripts/deploy"/*.sh 2>/dev/null || true
    sudo mkdir -p "${APP_DIR}/storage/framework"/{cache,sessions,testing,views} "${APP_DIR}/storage/logs" "${APP_DIR}/bootstrap/cache"
    sudo chown -R "${APP_USER}:www-data" "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
    sudo chmod -R ug+rwX,o-rwx "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"

    success "Ownership → ${APP_USER}:www-data; storage/ + bootstrap/cache/ writable."
}

step_env() {
    step "Environment file"
    ensure_db_password
    ensure_mail_config
    ensure_backup_password

    if ! sudo test -f "${APP_DIR}/.env"; then
        sudo -u "$APP_USER" cp "${APP_DIR}/.env.example" "${APP_DIR}/.env"
    fi

    # Generate APP_KEY if empty — the app won't boot without one.
    local current_key
    current_key="$(env_read APP_KEY)"
    if [[ -z "$current_key" || "$current_key" == "base64:" || "$current_key" == "base64:generate-me" ]]; then
        env_set APP_KEY "base64:$(openssl rand -base64 32)"
        info "Generated APP_KEY."
    fi

    local mailer="smtp"
    [[ -z "$MAIL_HOST" ]] && mailer="log"

    env_set APP_NAME             '"Bureau"'
    env_set APP_ENV              "production"
    env_set APP_DEBUG            "false"
    env_set APP_URL              "https://${DOMAIN}"
    env_set APP_LOCALE           "en"
    env_set APP_FALLBACK_LOCALE  "en"
    env_set BCRYPT_ROUNDS        "12"

    env_set DB_CONNECTION        "mariadb"
    env_set DB_HOST              "127.0.0.1"
    env_set DB_PORT              "3306"
    env_set DB_DATABASE          "bureau"
    env_set DB_USERNAME          "bureau"
    env_set DB_PASSWORD          "$DB_PASSWORD"

    # Session / cache / queue all on Redis — step_packages installs
    # redis-server + php-redis, so there's no extra dependency. RAM cost is
    # modest (~30 MB default) and every request shaves a DB round-trip vs
    # the database-backed drivers. Separate logical DBs so a cache flush
    # doesn't nuke sessions or pending queue jobs.
    env_set SESSION_DRIVER       "redis"
    env_set SESSION_CONNECTION   "session"
    env_set SESSION_LIFETIME     "120"
    env_set SESSION_ENCRYPT      "true"
    env_set SESSION_PATH         "/"
    env_set SESSION_DOMAIN       "$DOMAIN"
    env_set SESSION_SECURE_COOKIE "true"
    env_set SESSION_SAME_SITE    "lax"
    env_set CACHE_STORE          "redis"
    env_set QUEUE_CONNECTION     "redis"
    env_set BROADCAST_CONNECTION "log"
    env_set FILESYSTEM_DISK      "local"

    env_set REDIS_CLIENT         "phpredis"
    env_set REDIS_HOST           "127.0.0.1"
    env_set REDIS_PORT           "6379"
    env_set REDIS_PASSWORD       "null"
    env_set REDIS_DB             "0"
    env_set REDIS_CACHE_DB       "1"
    env_set REDIS_QUEUE_DB       "2"
    env_set REDIS_SESSION_DB     "3"
    # Point Laravel's queue driver at the dedicated 'queue' Redis connection
    # (config/database.php) so queued jobs land on REDIS_QUEUE_DB.
    env_set REDIS_QUEUE_CONNECTION "queue"

    env_set MAIL_MAILER          "$mailer"
    env_set MAIL_HOST            "${MAIL_HOST:-127.0.0.1}"
    env_set MAIL_PORT            "${MAIL_PORT:-2525}"
    env_set MAIL_USERNAME        "${MAIL_USER:-null}"
    env_set MAIL_PASSWORD        "${MAIL_PASS:-null}"
    env_set MAIL_FROM_ADDRESS    "\"${MAIL_USER:-noreply@${DOMAIN}}\""
    env_set MAIL_FROM_NAME       '"${APP_NAME}"'

    env_set LOG_CHANNEL          "stack"
    env_set LOG_STACK            "single"
    env_set LOG_LEVEL            "error"

    env_set BACKUP_ARCHIVE_PASSWORD "$BACKUP_ARCHIVE_PASSWORD"

    # .env carries APP_KEY, DB creds, OAuth secrets → owner:www-data, 640.
    sudo chown "${APP_USER}:www-data" "${APP_DIR}/.env"
    sudo chmod 640 "${APP_DIR}/.env"

    success ".env configured (mailer=${mailer})."
}

step_composer() {
    step "Composer dependencies"
    sudo -u "$APP_USER" bash -lc "cd '${APP_DIR}' && composer install --no-dev --optimize-autoloader --no-interaction --quiet"
    success "Composer packages installed."
}

step_frontend() {
    step "Frontend build"
    # Node/npm are symlinked in /usr/local/bin so bureau's shell finds them.
    sudo -u "$APP_USER" bash -lc "cd '${APP_DIR}' && npm ci --silent && npm run build"
    success "Vite build complete."
}

step_artisan() {
    step "Artisan — migrations and seeders"
    local art="sudo -u $APP_USER php ${APP_DIR}/artisan"

    $art migrate --force
    success "Migrations applied."

    # Seed the default household + owner user on a fresh DB. The seeder
    # falls back to an owner@bureau.homes / change-me pair unless
    # SEED_OWNER_* is populated.
    local user_count
    user_count="$($art tinker --execute='echo \App\Models\User::count();' 2>/dev/null | tail -1 | tr -d '[:space:]')"
    if [[ "${user_count:-0}" == "0" ]]; then
        $art db:seed --force
        success "Database seeded (household + owner user + starter categories)."
    else
        info "Database has ${user_count} user(s) — skipping seed."
    fi

    $art config:cache --quiet || true
    $art view:cache   --quiet || true
    $art event:cache  --quiet || true

    sudo systemctl reload "php${PHP_VER}-fpm" 2>/dev/null || true
    success "Caches warmed (FPM reloaded)."
}

step_storage_link() {
    step "Storage link"
    if [[ ! -L "${APP_DIR}/public/storage" ]]; then
        sudo -u "$APP_USER" php "${APP_DIR}/artisan" storage:link
        success "public/storage → ../storage/app/public."
    else
        info "public/storage already linked."
    fi
}

step_nginx() {
    step "Nginx configuration"
    command -v nginx &>/dev/null || die "nginx not installed — run step 'packages' first."

    local src="${APP_DIR}/scripts/deploy/nginx-bureau.conf"
    local dest="/etc/nginx/sites-available/bureau.conf"
    [[ -f "$src" ]] || die "template missing: $src"

    sudo sed -e "s|__DOMAIN__|${DOMAIN}|g" \
             -e "s|__APP_DIR__|${APP_DIR}|g" \
             -e "s|__PHP_VER__|${PHP_VER}|g" \
             "$src" | sudo tee "$dest" > /dev/null
    sudo chmod 644 "$dest"

    # If a Let's Encrypt cert already exists for this domain, wire the vhost
    # to it so re-runs don't revert to the self-signed placeholder.
    local le_cert="/etc/letsencrypt/live/${DOMAIN}"
    if sudo test -f "${le_cert}/fullchain.pem"; then
        sudo sed -i "s|ssl_certificate .*|ssl_certificate     ${le_cert}/fullchain.pem;|" "$dest"
        sudo sed -i "s|ssl_certificate_key .*|ssl_certificate_key ${le_cert}/privkey.pem;|" "$dest"
        info "Pointed vhost at existing Let's Encrypt cert."
    elif ! sudo test -f "/etc/ssl/bureau/fullchain.pem"; then
        # Short-lived self-signed placeholder so nginx boots before step_ssl.
        sudo mkdir -p /etc/ssl/bureau
        sudo openssl req -x509 -nodes -days 1 -newkey rsa:2048 \
            -keyout /etc/ssl/bureau/privkey.pem \
            -out    /etc/ssl/bureau/fullchain.pem \
            -subj   "/CN=${DOMAIN}" 2>/dev/null
        sudo chmod 640 /etc/ssl/bureau/privkey.pem
        info "Self-signed placeholder cert issued (valid 24h)."
    fi

    sudo mkdir -p /var/www/certbot
    sudo chown www-data:www-data /var/www/certbot

    sudo ln -sfn "$dest" /etc/nginx/sites-enabled/bureau.conf
    sudo rm -f /etc/nginx/sites-enabled/default
    sudo nginx -t
    sudo systemctl reload nginx
    success "Nginx vhost installed and reloaded."
}

step_ssl() {
    step "SSL — Let's Encrypt"
    ensure_admin_email
    command -v certbot &>/dev/null || sudo apt-get install -y -qq certbot

    sudo mkdir -p /var/www/certbot
    sudo chown www-data:www-data /var/www/certbot

    # Webroot flow so nginx keeps serving traffic during issuance + renewal.
    if ! sudo certbot certonly --webroot -w /var/www/certbot \
            -d "${DOMAIN}" -d "www.${DOMAIN}" \
            --email "$ADMIN_EMAIL" --agree-tos --non-interactive --keep-until-expiring; then
        warn "Multi-domain cert failed (DNS for www.${DOMAIN} missing?) — trying apex only…"
        sudo certbot certonly --webroot -w /var/www/certbot \
            -d "${DOMAIN}" \
            --email "$ADMIN_EMAIL" --agree-tos --non-interactive --keep-until-expiring
    fi

    local cert="/etc/letsencrypt/live/${DOMAIN}"
    sudo sed -i "s|ssl_certificate .*|ssl_certificate     ${cert}/fullchain.pem;|" \
        /etc/nginx/sites-available/bureau.conf
    sudo sed -i "s|ssl_certificate_key .*|ssl_certificate_key ${cert}/privkey.pem;|" \
        /etc/nginx/sites-available/bureau.conf

    sudo nginx -t && sudo systemctl reload nginx

    sudo mkdir -p /etc/letsencrypt/renewal-hooks/deploy
    sudo tee /etc/letsencrypt/renewal-hooks/deploy/nginx-reload.sh > /dev/null <<'HOOK'
#!/usr/bin/env bash
systemctl reload nginx
HOOK
    sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/nginx-reload.sh

    success "SSL installed; auto-renewal hook configured."
}

step_queue_worker() {
    step "Queue worker — systemd unit"
    # Plain `queue:work` — Bureau is single-tenant / low-volume. Horizon
    # forces a Redis dependency and adds dashboard UI we don't need at
    # this scale. Graduate to Horizon if load justifies it.
    sudo tee /etc/systemd/system/bureau-queue.service > /dev/null <<UNIT
[Unit]
Description=Bureau Laravel queue worker
After=network.target mariadb.service

[Service]
User=${APP_USER}
Group=www-data
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/php ${APP_DIR}/artisan queue:work --sleep=3 --tries=3 --backoff=30 --max-time=3600
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=60
StandardOutput=journal
StandardError=journal
SyslogIdentifier=bureau-queue

[Install]
WantedBy=multi-user.target
UNIT
    sudo chmod 644 /etc/systemd/system/bureau-queue.service
    sudo systemctl daemon-reload
    sudo systemctl enable bureau-queue
    sudo systemctl restart bureau-queue
    success "bureau-queue.service enabled and running (journalctl -u bureau-queue -f)."
}

step_scheduler() {
    step "Scheduler — cron"
    id "$APP_USER" &>/dev/null || die "user '${APP_USER}' not found — run step 'app-user' first."

    local line="* * * * * cd ${APP_DIR} && /usr/bin/php ${APP_DIR}/artisan schedule:run >> /dev/null 2>&1"
    (sudo crontab -u "$APP_USER" -l 2>/dev/null | grep -v "artisan schedule:run"; echo "$line") \
        | sudo crontab -u "$APP_USER" -
    success "schedule:run cron installed for user '${APP_USER}' (minutely)."
}

step_firewall() {
    step "Firewall — UFW"
    if ! command -v ufw &>/dev/null; then
        warn "ufw not found — skipping firewall step."
        return
    fi
    sudo ufw --force reset > /dev/null
    sudo ufw default deny incoming
    sudo ufw default allow outgoing
    sudo ufw allow OpenSSH
    sudo ufw allow 'Nginx Full'
    sudo ufw --force enable
    success "UFW enabled: SSH + Nginx Full."
}

# =============================================================================
# RUN
# =============================================================================
run_step packages     step_packages
run_step mariadb      step_mariadb
run_step app-user     step_app_user
run_step permissions  step_permissions
run_step env          step_env
run_step composer     step_composer
run_step frontend     step_frontend
run_step artisan      step_artisan
run_step storage-link step_storage_link
run_step nginx        step_nginx
run_step ssl          step_ssl
run_step queue-worker step_queue_worker
run_step scheduler    step_scheduler
run_step firewall     step_firewall

echo
echo -e "${GREEN}${BOLD}━━━  Done  ━━━${RESET}"
echo
if [[ ${#ONLY_STEPS[@]} -eq 0 ]]; then
    echo -e "  ${BOLD}App:${RESET}     https://${DOMAIN}"
    echo -e "  ${BOLD}Health:${RESET}  https://${DOMAIN}/up"
    echo -e "  ${BOLD}Nginx:${RESET}   /etc/nginx/sites-available/bureau.conf"
    echo -e "  ${BOLD}Cert:${RESET}    /etc/letsencrypt/live/${DOMAIN}/"
    echo -e "  ${BOLD}Queue:${RESET}   systemctl status bureau-queue  |  journalctl -u bureau-queue -f"
    echo -e "  ${BOLD}Cron:${RESET}    sudo crontab -u ${APP_USER} -l"
    echo
    echo -e "  ${BOLD}${YELLOW}Save these credentials now — they are not stored on disk.${RESET}"
    echo
    echo "    Domain:   ${DOMAIN}"
    echo "    App dir:  ${APP_DIR}"
    echo "    App user: ${APP_USER}"
    echo "    MariaDB:  bureau / bureau / ${DB_PASSWORD}"
    echo "    Backup:   ${BACKUP_ARCHIVE_PASSWORD}"
    echo "    Mail:     ${MAIL_HOST:-(log driver)}${MAIL_HOST:+:${MAIL_PORT} / ${MAIL_USER}}"
fi

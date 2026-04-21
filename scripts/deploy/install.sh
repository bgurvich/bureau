#!/usr/bin/env bash
# =============================================================================
# Laravel unified installer
#
# Generic installer for a Laravel app: packages → MariaDB → OS user →
# permissions → .env → composer → frontend build → artisan → nginx → TLS
# → queue worker → scheduler. Runnable by any user with sudo; escalates
# only where needed. Bureau uses it as-is; fork for another Laravel app
# by tweaking the CONFIG block below or setting env vars at the call site.
#
# Usage:
#   bash scripts/deploy/install.sh                        # run all steps
#   bash scripts/deploy/install.sh --dev                  # local-dev mode
#   bash scripts/deploy/install.sh --only nginx ssl       # subset
#   bash scripts/deploy/install.sh --skip firewall        # all except
#   bash scripts/deploy/install.sh --force nginx          # re-run one step
#   bash scripts/deploy/install.sh --force all            # re-run everything
#
# `--only <step>` implies `--force <step>` — explicit-name always runs.
# `--dev` skips ssl + firewall and flips .env values (APP_ENV=local,
# APP_DEBUG=true, APP_URL=http://, SESSION_SECURE_COOKIE=false, LOG_LEVEL=debug).
#
# Available steps (in order):
#   packages, mariadb, app-user, permissions, env, composer, frontend,
#   artisan, storage-link, owner, nginx, ssl, queue-worker, scheduler,
#   firewall.
#
# `--only owner` is the password-reset / owner-change hatch for an
# already-provisioned box: prompts, writes SEED_OWNER_* to .env, and
# updates the live user in-place.
#
# firewall is off unless you pass `--only firewall`. Everything else runs
# by default.
#
# Completion markers in /var/lib/${APP_NAME}-install/. Safe to re-run.
# =============================================================================
set -euo pipefail
umask 077
export DEBIAN_FRONTEND=noninteractive

# ═════════════════════════════════════════════════════════════════════════════
# CONFIG — override via env vars at invocation, or edit defaults for a fork.
# ═════════════════════════════════════════════════════════════════════════════

# App identity. APP_NAME is the slug used for DB, OS user, systemd unit,
# nginx site, marker dir, and backup archive prefix. DISPLAY_NAME goes into
# the .env APP_NAME (shown in the UI).
APP_NAME="${APP_NAME:-bureau}"
DISPLAY_NAME="${DISPLAY_NAME:-Bureau}"

APP_USER="${APP_USER:-$APP_NAME}"            # OS user that runs queue + scheduler
DB_NAME="${DB_NAME:-$APP_NAME}"
DB_USER="${DB_USER:-$APP_NAME}"

# Domain the app is served at. Used by .env APP_URL, nginx template, certbot.
DOMAIN="${DOMAIN:-${APP_NAME}.homes}"

# Language runtimes.
PHP_VER="${PHP_VER:-8.3}"
NODE_VER="${NODE_VER:-22}"
NVM_DIR="${NVM_DIR:-/opt/nvm}"

# PHP extensions. Each entry becomes `php${PHP_VER}-${mod}`.
PHP_MODULES_DEFAULT="fpm cli mysql redis mbstring xml curl zip bcmath intl gd opcache"
PHP_MODULES="${PHP_MODULES:-$PHP_MODULES_DEFAULT}"

# OS packages layered on top of the Laravel base set. Bureau needs OCR +
# PDF thumbnailing + encrypted-zip backup. A different app might list less
# or more — set EXTRA_APT_PACKAGES="" to install only the base.
EXTRA_APT_PACKAGES_DEFAULT="tesseract-ocr tesseract-ocr-eng imagemagick poppler-utils p7zip-full"
EXTRA_APT_PACKAGES="${EXTRA_APT_PACKAGES-$EXTRA_APT_PACKAGES_DEFAULT}"

# Where the nginx vhost template lives (relative to APP_DIR) and what name
# to install it under in /etc/nginx/sites-{available,enabled}/.
NGINX_TEMPLATE="${NGINX_TEMPLATE:-scripts/deploy/nginx-${APP_NAME}.conf}"
NGINX_SITE="${NGINX_SITE:-${APP_NAME}.conf}"

# Systemd queue-worker unit name (without `.service`).
QUEUE_UNIT="${QUEUE_UNIT:-${APP_NAME}-queue}"

# Where completion markers live. Root-owned; created via sudo on first run.
MARK_DIR="${MARK_DIR:-/var/lib/${APP_NAME}-install}"

# Self-signed TLS placeholder location (used when Let's Encrypt hasn't run yet).
SSL_PLACEHOLDER_DIR="${SSL_PLACEHOLDER_DIR:-/etc/ssl/${APP_NAME}}"

# Validate identifiers that end up in SQL / systemd / nginx paths.
for _id in "$APP_NAME" "$APP_USER" "$DB_NAME" "$DB_USER" "$QUEUE_UNIT"; do
    [[ "$_id" =~ ^[A-Za-z0-9_-]+$ ]] \
        || { echo "[error] Invalid identifier '${_id}' — must match [A-Za-z0-9_-]+" >&2; exit 1; }
done

# ═════════════════════════════════════════════════════════════════════════════

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

info()    { echo -e "${CYAN}[info]${RESET}  $*"; }
success() { echo -e "${GREEN}[ok]${RESET}    $*"; }
warn()    { echo -e "${YELLOW}[warn]${RESET}  $*"; }
die()     { echo -e "${RED}[error]${RESET} $*" >&2; exit 1; }
step()    { echo -e "\n${BOLD}━━━  $*  ━━━${RESET}"; }

# Early-exit for --help so operators can inspect options without needing
# sudo cached first. Full flag parsing happens lower — this is a quick peek.
for _arg in "$@"; do
    if [[ "$_arg" == "-h" || "$_arg" == "--help" ]]; then
        awk '/^# ====/{cnt++; if (cnt==2) exit} NR==1{next} /^#/{print} !/^#/{exit}' "$0" | sed 's/^# \{0,1\}//'
        echo
        echo "Configurable (env var = default):"
        echo "  APP_NAME           = bureau"
        echo "  DISPLAY_NAME       = Bureau"
        echo "  APP_USER           = \$APP_NAME"
        echo "  DB_NAME            = \$APP_NAME"
        echo "  DB_USER            = \$APP_NAME"
        echo "  DOMAIN             = \${APP_NAME}.homes"
        echo "  PHP_VER            = 8.3"
        echo "  NODE_VER           = 22"
        echo "  PHP_MODULES        = fpm cli mysql redis mbstring xml curl zip bcmath intl gd opcache"
        echo "  EXTRA_APT_PACKAGES = tesseract-ocr tesseract-ocr-eng imagemagick poppler-utils p7zip-full"
        echo "  NGINX_TEMPLATE     = scripts/deploy/nginx-\${APP_NAME}.conf"
        echo "  NGINX_SITE         = \${APP_NAME}.conf"
        echo "  QUEUE_UNIT         = \${APP_NAME}-queue"
        echo
        echo "Mail (env step — prompted if not set):"
        echo "  MAIL_PROVIDER      = log|postmark|smtp"
        echo "  POSTMARK_API_KEY   = <server token>            # when MAIL_PROVIDER=postmark"
        echo "  POSTMARK_WEBHOOK_USER / _PASSWORD                # inbound-webhook basic-auth pair (auto-gen if blank)"
        echo "  MAIL_HOST/PORT/USER/PASS/ENCRYPTION            # when MAIL_PROVIDER=smtp"
        echo "  MAIL_FROM_ADDRESS  = notifications@\${DOMAIN}"
        echo
        echo "Owner (owner step — prompted if not set):"
        echo "  OWNER_EMAIL        = owner@\${DOMAIN}"
        echo "  OWNER_NAME         = Owner"
        echo "  OWNER_PASSWORD     = <24-char random if blank>"
        echo
        echo "Example: APP_NAME=myapp DOMAIN=myapp.example.com bash install.sh"
        echo "Example: MAIL_PROVIDER=postmark POSTMARK_API_KEY=xxx bash install.sh --only env"
        exit 0
    fi
done

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
DEV_MODE=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dev)
            # Local / non-production install. Skips the bits that only make
            # sense on a public host (TLS cert issuance, firewall) and flips
            # the .env values that production would lock down (APP_ENV=local,
            # APP_DEBUG=true, cookies not secure-only, etc.). Everything else
            # still runs so the app actually works on the dev box.
            DEV_MODE=true
            shift
            continue
            ;;
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
    esac
    shift
done

# ── Completion markers ───────────────────────────────────────────────────────
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

    # firewall is opt-in: only runs when named via --only firewall.
    if [[ "$name" == "firewall" && ${#ONLY_STEPS[@]} -eq 0 ]]; then
        return 1
    fi

    # In dev mode, skip public-host-only steps unless the operator
    # explicitly names them via --only. Keeps `--dev` → no Let's Encrypt
    # attempt on a box whose DNS doesn't point anywhere.
    if [[ "$DEV_MODE" == true && ${#ONLY_STEPS[@]} -eq 0 ]]; then
        case "$name" in
            ssl|firewall) return 1 ;;
        esac
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

# An explicitly-named step with --only implies --force: if you're asking for
# `--only ssl` after ssl.done exists, the intent is "re-run ssl", not "skip
# silently." Saves the second flag in the common re-run case.
is_only_named() {
    local name="$1"
    [[ " ${ONLY_STEPS[*]} " == *" $name "* ]] && return 0
    [[ " ${ONLY_STEPS[*]} " == *" certbot "* && "$name" == "ssl" ]] && return 0
    return 1
}

run_step() {
    local name="$1" fn="$2"
    should_run "$name" || return 0
    if already_done "$name" && ! is_forced "$name" && ! is_only_named "$name"; then
        info "skip ${name} — already completed $(cat "${MARK_DIR}/${name}.done")"
        return 0
    fi
    if is_forced "$name" || is_only_named "$name"; then
        mark_clear "$name"
    fi
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
MAIL_PROVIDER="${MAIL_PROVIDER:-}"      # log | postmark | smtp (prompted if blank)
MAIL_HOST="${MAIL_HOST:-}"
MAIL_PORT="${MAIL_PORT:-}"
MAIL_USER="${MAIL_USER:-}"
MAIL_PASS="${MAIL_PASS:-}"
MAIL_FROM_ADDRESS="${MAIL_FROM_ADDRESS:-}"
MAIL_ENCRYPTION="${MAIL_ENCRYPTION:-}"
POSTMARK_API_KEY="${POSTMARK_API_KEY:-}"
POSTMARK_WEBHOOK_USER="${POSTMARK_WEBHOOK_USER:-}"
POSTMARK_WEBHOOK_PASSWORD="${POSTMARK_WEBHOOK_PASSWORD:-}"
OWNER_EMAIL="${OWNER_EMAIL:-}"
OWNER_NAME="${OWNER_NAME:-}"
OWNER_PASSWORD="${OWNER_PASSWORD:-}"
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
    local install_user="${SUDO_USER:-$USER}"
    sudo install -m 640 -o "$install_user" -g www-data "$tmp" "$env"
    rm -f "$tmp"
}

# APP_DIR and DOMAIN are needed for almost every step — collect once up-front.
# Per-step data (DB password, mail creds, admin email, backup password) is
# prompted lazily inside the step that needs it so `--only nginx` doesn't ask
# for the DB password it'll never use.
prompt APP_DIR "Application directory" "$APP_DIR"

# If .env already exists, pull the domain out of APP_URL as the default so
# a re-run defaults to what's already live instead of bureau.homes.
if [[ -z "${DOMAIN_EXPLICITLY_SET:-}" ]] && sudo test -f "${APP_DIR}/.env"; then
    _app_url="$(env_read APP_URL)"
    if [[ -n "$_app_url" ]]; then
        # Strip scheme + any path → just the host.
        _domain_from_env="${_app_url#http://}"; _domain_from_env="${_domain_from_env#https://}"
        _domain_from_env="${_domain_from_env%%/*}"
        [[ -n "$_domain_from_env" ]] && DOMAIN="$_domain_from_env"
    fi
fi
prompt DOMAIN  "Domain"                 "$DOMAIN"

# Lazy prompters — first call prompts, subsequent calls return the cached value
# so two steps needing the same input only ask once.
ensure_db_password() {
    if [[ -z "$DB_PASSWORD" ]]; then
        local default
        default="$(env_read DB_PASSWORD)"
        [[ -n "$default" ]] || default="$(openssl rand -base64 18)"
        prompt DB_PASSWORD "MariaDB password for '${DB_USER}' user" "$default" secret
    fi
}
ensure_mail_config() {
    # Three outbound paths:
    #   log      → mail goes to storage/logs/laravel.log, nothing sent.
    #   postmark → Laravel's native postmark transport via HTTP API. No SMTP
    #              plumbing, just the server token. Recommended for production.
    #   smtp     → generic SMTP (any provider or self-hosted MTA).
    # $MAIL_PROVIDER can be pre-set in env to bypass the interactive picker.
    [[ -n "${_MAIL_PROMPTED:-}" ]] && return

    # On a re-run, default every prompt from whatever's currently in .env so
    # the operator can just press Enter through the existing config.
    local cur_mailer cur_host cur_port cur_user cur_pass cur_enc cur_from cur_pmk
    cur_mailer="$(env_read MAIL_MAILER)"
    cur_host="$(env_read MAIL_HOST)"
    cur_port="$(env_read MAIL_PORT)"
    cur_user="$(env_read MAIL_USERNAME)"
    cur_pass="$(env_read MAIL_PASSWORD)"
    cur_enc="$(env_read MAIL_ENCRYPTION)"
    cur_from="$(env_read MAIL_FROM_ADDRESS)"
    cur_pmk="$(env_read POSTMARK_API_KEY)"
    local cur_pmk_wuser cur_pmk_wpass
    cur_pmk_wuser="$(env_read POSTMARK_WEBHOOK_USER)"
    cur_pmk_wpass="$(env_read POSTMARK_WEBHOOK_PASSWORD)"

    if [[ -z "${MAIL_PROVIDER:-}" ]]; then
        prompt MAIL_PROVIDER "Mail provider [log/postmark/smtp]" "${cur_mailer:-log}"
    fi

    case "$MAIL_PROVIDER" in
        postmark)
            prompt POSTMARK_API_KEY  "Postmark server API token"     "$cur_pmk"  secret
            prompt MAIL_FROM_ADDRESS "Outbound From: address"        "${cur_from:-notifications@${DOMAIN}}"

            # Inbound webhook is optional — only needed if you plan to
            # forward mail into Bureau (`/webhooks/postmark/inbound` feeds
            # the mail_messages table + draft transactions from receipts).
            # Outbound reminders / digests / magic links only need the
            # POSTMARK_API_KEY above.
            local default_inbound="n"
            [[ -n "$cur_pmk_wuser" || -n "$cur_pmk_wpass" ]] && default_inbound="y"
            local configure_inbound
            prompt configure_inbound "Configure Postmark inbound webhook? [y/N]" "$default_inbound"
            if [[ "${configure_inbound,,}" == "y" || "${configure_inbound,,}" == "yes" ]]; then
                # Auto-generate strong creds on first run; press-Enter-to-keep
                # on re-runs so existing Postmark URL config stays valid.
                [[ -n "$cur_pmk_wuser" ]] || cur_pmk_wuser="pmk-$(openssl rand -hex 8)"
                [[ -n "$cur_pmk_wpass" ]] || cur_pmk_wpass="$(openssl rand -hex 24)"
                prompt POSTMARK_WEBHOOK_USER     "Postmark inbound webhook username" "$cur_pmk_wuser"
                prompt POSTMARK_WEBHOOK_PASSWORD "Postmark inbound webhook password" "$cur_pmk_wpass" secret
            fi
            ;;
        smtp)
            prompt MAIL_HOST         "SMTP host"                     "${cur_host:-smtp.example.com}"
            prompt MAIL_PORT         "SMTP port"                     "${cur_port:-587}"
            prompt MAIL_USER         "SMTP username"                 "${cur_user:-noreply@${DOMAIN}}"
            prompt MAIL_PASS         "SMTP password"                 "$cur_pass" secret
            prompt MAIL_ENCRYPTION   "SMTP encryption [tls/ssl]"     "${cur_enc:-tls}"
            prompt MAIL_FROM_ADDRESS "Outbound From: address"        "${cur_from:-${MAIL_USER:-noreply@${DOMAIN}}}"
            ;;
        log|"")
            MAIL_PROVIDER="log"
            ;;
        *)
            die "MAIL_PROVIDER must be one of: log, postmark, smtp (got '${MAIL_PROVIDER}')"
            ;;
    esac
    _MAIL_PROMPTED=1
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
        # Prefer whatever's already the From address — the operator has likely
        # already proven they control that mailbox. Fall back to admin@DOMAIN.
        local default
        default="$(env_read MAIL_FROM_ADDRESS)"
        default="${default:-admin@${DOMAIN}}"
        prompt ADMIN_EMAIL "Admin / Let's Encrypt email" "$default"
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

    # Laravel stack. PHP modules from the $PHP_MODULES config var expand to
    # `php${PHP_VER}-${mod}` so forks can drop gd/intl/opcache or add pgsql/
    # soap/etc. without touching the script body.
    local php_pkgs=()
    for mod in $PHP_MODULES; do
        php_pkgs+=("php${PHP_VER}-${mod}")
    done
    sudo apt-get install -y --no-install-recommends \
        nginx mariadb-server redis-server composer \
        "${php_pkgs[@]}" \
        certbot

    # Extra packages declared in $EXTRA_APT_PACKAGES. Empty string = skip.
    if [[ -n "$EXTRA_APT_PACKAGES" ]]; then
        # shellcheck disable=SC2086
        sudo apt-get install -y --no-install-recommends $EXTRA_APT_PACKAGES
    fi

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

    # $DB_NAME / $DB_USER are validated against [A-Za-z0-9_]+ at the top of
    # the script so they can safely interpolate into identifier positions.
    sudo mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${esc_pw}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${esc_pw}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
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

    success "MariaDB: database '${DB_NAME}' and user '${DB_USER}'@localhost ready."
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

    # INSTALL_USER is whoever invoked `sudo install.sh` — keeps them as the
    # repo owner so `git pull` / editor workflow keeps working after
    # provisioning. APP_USER (bureau) runs the queue worker + scheduler and
    # needs RO on code + RW on storage/bootstrap/cache — it gets that via
    # the www-data group.
    local install_user="${SUDO_USER:-$USER}"

    # Ancestor traverse — nginx / php-fpm runs as www-data and needs +x on
    # every directory above APP_DIR to reach public/index.php. Home
    # directories default to 0700 (no traverse for anyone but the owner),
    # which blocks that path. Grant o+x up the chain from APP_DIR to /.
    local parent="$APP_DIR"
    while [[ "$parent" != "/" && -n "$parent" ]]; do
        sudo chmod o+x "$parent" 2>/dev/null || true
        parent="$(dirname "$parent")"
    done

    # Add bureau to www-data group so it can read .env (640 owner+group)
    # and write to storage via group perms. Idempotent.
    sudo usermod -a -G www-data "$APP_USER" 2>/dev/null || true

    sudo chown -R "${install_user}:www-data" "$APP_DIR"

    # Dirs 2755 (setgid so new files inherit www-data group), files 644 —
    # world-readable but not writable, which is the canonical Laravel
    # deploy shape. php-fpm (www-data) reads via group, everyone else
    # just reads. No world-write anywhere.
    sudo find "$APP_DIR" -type d -exec chmod 2755 {} +
    sudo find "$APP_DIR" -type f -exec chmod 644 {} +
    [[ -f "${APP_DIR}/artisan" ]] && sudo chmod +x "${APP_DIR}/artisan"
    [[ -d "${APP_DIR}/vendor/bin" ]] && sudo chmod -R +x "${APP_DIR}/vendor/bin" 2>/dev/null || true
    [[ -d "${APP_DIR}/node_modules/.bin" ]] && sudo chmod -R +x "${APP_DIR}/node_modules/.bin" 2>/dev/null || true
    [[ -d "${APP_DIR}/scripts/deploy" ]] && sudo chmod +x "${APP_DIR}/scripts/deploy"/*.sh 2>/dev/null || true

    # Writable dirs — group-writable + setgid so bureau (in www-data group)
    # can write queue-log / cache files without escalation. Still o-rwx.
    sudo mkdir -p "${APP_DIR}/storage/framework"/{cache,sessions,testing,views} "${APP_DIR}/storage/logs" "${APP_DIR}/bootstrap/cache"
    sudo chown -R "${install_user}:www-data" "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
    sudo chmod -R u+rwX,g+rwX,o-rwx "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
    sudo find "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" -type d -exec chmod g+s {} +

    success "Owner → ${install_user}:www-data; ${APP_USER} added to www-data group; storage/ + bootstrap/cache/ group-writable."
}

step_env() {
    step "Environment file"
    ensure_db_password
    ensure_mail_config
    ensure_backup_password

    local install_user="${SUDO_USER:-$USER}"
    if ! sudo test -f "${APP_DIR}/.env"; then
        sudo -u "$install_user" cp "${APP_DIR}/.env.example" "${APP_DIR}/.env"
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

    # Dev mode relaxes the env values that production would lock down.
    # APP_URL stays http://DOMAIN (no TLS), debug is on, bcrypt rounds
    # drop for faster seeder iteration. Log level is verbose. Cookies are
    # not secure-only (would break local http testing).
    local app_env="production"
    local app_debug="false"
    local app_url="https://${DOMAIN}"
    local bcrypt_rounds="12"
    local log_level="error"
    local session_secure="true"
    if [[ "$DEV_MODE" == true ]]; then
        app_env="local"
        app_debug="true"
        app_url="http://${DOMAIN}"
        bcrypt_rounds="4"
        log_level="debug"
        session_secure="false"
    fi

    # ensure_mail_config already ran (via env step preamble) — $MAIL_PROVIDER
    # is one of log|postmark|smtp at this point.
    local mailer="${MAIL_PROVIDER:-log}"

    env_set APP_NAME             "\"${DISPLAY_NAME}\""
    env_set APP_ENV              "$app_env"
    env_set APP_DEBUG            "$app_debug"
    env_set APP_URL              "$app_url"
    env_set APP_LOCALE           "en"
    env_set APP_FALLBACK_LOCALE  "en"
    env_set BCRYPT_ROUNDS        "$bcrypt_rounds"

    env_set DB_CONNECTION        "mariadb"
    env_set DB_HOST              "127.0.0.1"
    env_set DB_PORT              "3306"
    env_set DB_DATABASE          "$DB_NAME"
    env_set DB_USERNAME          "$DB_USER"
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
    env_set SESSION_SECURE_COOKIE "$session_secure"
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

    # Mail transport — shape depends on $MAIL_PROVIDER (log/postmark/smtp).
    # From-address is common to all. Postmark's native transport bypasses
    # SMTP entirely (uses the HTTP API via POSTMARK_API_KEY) so host/port/
    # user/pass stay unset on that path. Config / services.php already has
    # the postmark block that reads POSTMARK_API_KEY.
    env_set MAIL_MAILER          "$mailer"
    env_set MAIL_FROM_NAME       '"${APP_NAME}"'
    env_set MAIL_FROM_ADDRESS    "\"${MAIL_FROM_ADDRESS:-noreply@${DOMAIN}}\""

    case "$mailer" in
        postmark)
            env_set POSTMARK_API_KEY          "$POSTMARK_API_KEY"
            # Inbound webhook auth — gates /webhooks/postmark/inbound.
            # WebhookAuthTest fails closed in production when either is blank.
            env_set POSTMARK_WEBHOOK_USER     "${POSTMARK_WEBHOOK_USER:-}"
            env_set POSTMARK_WEBHOOK_PASSWORD "${POSTMARK_WEBHOOK_PASSWORD:-}"
            ;;
        smtp)
            env_set MAIL_HOST            "$MAIL_HOST"
            env_set MAIL_PORT            "${MAIL_PORT:-587}"
            env_set MAIL_USERNAME        "${MAIL_USER:-null}"
            env_set MAIL_PASSWORD        "${MAIL_PASS:-null}"
            env_set MAIL_ENCRYPTION      "${MAIL_ENCRYPTION:-tls}"
            ;;
        log)
            # Nothing else to set; laravel.log captures the raw message.
            ;;
    esac

    env_set LOG_CHANNEL          "stack"
    env_set LOG_STACK            "single"
    env_set LOG_LEVEL            "$log_level"

    env_set BACKUP_ARCHIVE_PASSWORD "$BACKUP_ARCHIVE_PASSWORD"

    # .env carries APP_KEY, DB creds, OAuth secrets. Owner is the installing
    # user so `git pull` / editing stays ergonomic; group www-data so php-fpm
    # (www-data) and bureau (added to www-data in step_permissions) can read.
    # Mode 640 — no world access.
    sudo chown "${install_user}:www-data" "${APP_DIR}/.env"
    sudo chmod 640 "${APP_DIR}/.env"

    success ".env configured (mailer=${mailer})."
}

step_composer() {
    step "Composer dependencies"
    # Run as the installing user (owns the repo). composer's "don't run as
    # root" warning is avoided because the installer itself runs as a
    # regular user — that was the whole reason we refactored away from
    # `sudo bash install.sh`.
    local install_user="${SUDO_USER:-$USER}"
    sudo -u "$install_user" bash -lc "cd '${APP_DIR}' && composer install --no-dev --optimize-autoloader --no-interaction --quiet"
    success "Composer packages installed."
}

step_frontend() {
    step "Frontend build"
    local install_user="${SUDO_USER:-$USER}"
    sudo -u "$install_user" bash -lc "cd '${APP_DIR}' && npm ci --silent && npm run build"
    success "Vite build complete."
}

step_artisan() {
    step "Artisan — migrations and seeders"
    # artisan runs as install_user too — needs to read .env (via www-data
    # group) and write storage (via group). Migrations use PDO so no
    # filesystem writes beyond the compiled cache.
    local install_user="${SUDO_USER:-$USER}"
    local art="sudo -u $install_user php ${APP_DIR}/artisan"

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
    local install_user="${SUDO_USER:-$USER}"
    if [[ ! -L "${APP_DIR}/public/storage" ]]; then
        sudo -u "$install_user" php "${APP_DIR}/artisan" storage:link
        success "public/storage → ../storage/app/public."
    else
        info "public/storage already linked."
    fi
}

step_owner() {
    # App owner account — the first user created by DatabaseSeeder when the
    # users table is empty. Prompting here does double duty:
    #   Fresh install: writes SEED_OWNER_* in .env before step_artisan runs,
    #                  so db:seed picks up the operator's chosen creds
    #                  (vs baking "owner@bureau.homes / change-me").
    #   Re-run:        DB already has the owner user; prompts default from
    #                  whatever's in .env now, updates the live user in-place
    #                  so this doubles as a "change the admin password" hatch.
    step "App owner account"
    local install_user="${SUDO_USER:-$USER}"

    local cur_email cur_name cur_pass
    cur_email="$(env_read SEED_OWNER_EMAIL)"
    cur_name="$(env_read SEED_OWNER_NAME)"
    cur_pass="$(env_read SEED_OWNER_PASSWORD)"

    prompt OWNER_EMAIL "App owner email"    "${cur_email:-owner@${DOMAIN}}"
    prompt OWNER_NAME  "App owner name"     "${cur_name:-Owner}"

    # Auto-generate a strong default password on first run. Re-run keeps the
    # existing value unless the operator types a new one.
    local default_pw="$cur_pass"
    [[ -z "$default_pw" ]] && default_pw="$(openssl rand -base64 24 | tr -d '/=+\n' | head -c 24)"
    prompt OWNER_PASSWORD "App owner password" "$default_pw" secret

    env_set SEED_OWNER_EMAIL    "$OWNER_EMAIL"
    env_set SEED_OWNER_NAME     "\"${OWNER_NAME}\""
    env_set SEED_OWNER_PASSWORD "$OWNER_PASSWORD"

    # If the DB + users table exist and a user already lives there, update it
    # in-place so existing sessions stay valid (email/name/password change
    # without a re-seed). Skip silently on a fresh install — db:seed will
    # create the user from the .env values in the artisan step.
    if sudo -u "$install_user" \
        PREV_EMAIL="$cur_email" \
        NEW_EMAIL="$OWNER_EMAIL" \
        NEW_NAME="$OWNER_NAME" \
        NEW_PASSWORD="$OWNER_PASSWORD" \
        php "${APP_DIR}/artisan" tinker --execute='
            if (! \Schema::hasTable("users")) { echo "no-table"; return; }
            $prev = getenv("PREV_EMAIL");
            $u = ($prev ? \App\Models\User::where("email", $prev)->first() : null)
                ?? \App\Models\User::orderBy("id")->first();
            if (! $u) { echo "no-user"; return; }
            $u->forceFill([
                "email"    => getenv("NEW_EMAIL"),
                "name"     => getenv("NEW_NAME"),
                "password" => \Illuminate\Support\Facades\Hash::make(getenv("NEW_PASSWORD")),
            ])->save();
            echo "updated:" . $u->id;
        ' 2>/dev/null | tail -1 | grep -q "^updated:"; then
        success "Owner '${OWNER_EMAIL}' updated in-place (existing user)."
    else
        info "No existing owner user — .env updated; db:seed will create it on the artisan step."
    fi
}

step_nginx() {
    step "Nginx configuration"
    command -v nginx &>/dev/null || die "nginx not installed — run step 'packages' first."

    local src="${APP_DIR}/${NGINX_TEMPLATE}"
    local dest="/etc/nginx/sites-available/${NGINX_SITE}"
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
    elif ! sudo test -f "${SSL_PLACEHOLDER_DIR}/fullchain.pem"; then
        # Short-lived self-signed placeholder so nginx boots before step_ssl.
        sudo mkdir -p "$SSL_PLACEHOLDER_DIR"
        sudo openssl req -x509 -nodes -days 1 -newkey rsa:2048 \
            -keyout "${SSL_PLACEHOLDER_DIR}/privkey.pem" \
            -out    "${SSL_PLACEHOLDER_DIR}/fullchain.pem" \
            -subj   "/CN=${DOMAIN}" 2>/dev/null
        sudo chmod 640 "${SSL_PLACEHOLDER_DIR}/privkey.pem"
        info "Self-signed placeholder cert issued (valid 24h)."
    fi

    sudo mkdir -p /var/www/certbot
    sudo chown www-data:www-data /var/www/certbot

    sudo ln -sfn "$dest" "/etc/nginx/sites-enabled/${NGINX_SITE}"
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

    # Build the -d list dynamically: include www.DOMAIN only if its DNS
    # actually resolves, so re-running after the user adds the A record
    # upgrades the cert without the first attempt failing on a stale
    # single-domain cert. `keep-until-expiring` is safe — it's a no-op
    # when the existing cert already covers the requested names.
    local cert_args=(-d "${DOMAIN}")
    if getent hosts "www.${DOMAIN}" >/dev/null 2>&1; then
        cert_args+=(-d "www.${DOMAIN}")
        info "DNS for www.${DOMAIN} resolves — requesting SAN cert."
    else
        warn "DNS for www.${DOMAIN} does not resolve — issuing apex-only cert. Re-run '--only ssl' after adding the A record to upgrade."
    fi

    # Webroot flow so nginx keeps serving traffic during issuance + renewal.
    # --expand lets a subsequent run add www.DOMAIN to an existing apex-only
    # cert without rejecting the request.
    sudo certbot certonly --webroot -w /var/www/certbot \
        "${cert_args[@]}" \
        --expand \
        --email "$ADMIN_EMAIL" --agree-tos --non-interactive --keep-until-expiring

    local cert="/etc/letsencrypt/live/${DOMAIN}"
    sudo sed -i "s|ssl_certificate .*|ssl_certificate     ${cert}/fullchain.pem;|" \
        "/etc/nginx/sites-available/${NGINX_SITE}"
    sudo sed -i "s|ssl_certificate_key .*|ssl_certificate_key ${cert}/privkey.pem;|" \
        "/etc/nginx/sites-available/${NGINX_SITE}"

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
    sudo tee "/etc/systemd/system/${QUEUE_UNIT}.service" > /dev/null <<UNIT
[Unit]
Description=${DISPLAY_NAME} Laravel queue worker
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
SyslogIdentifier=${QUEUE_UNIT}

[Install]
WantedBy=multi-user.target
UNIT
    sudo chmod 644 "/etc/systemd/system/${QUEUE_UNIT}.service"
    sudo systemctl daemon-reload
    sudo systemctl enable "$QUEUE_UNIT"
    sudo systemctl restart "$QUEUE_UNIT"
    success "${QUEUE_UNIT}.service enabled and running (journalctl -u ${QUEUE_UNIT} -f)."
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
run_step owner        step_owner
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
    echo -e "  ${BOLD}Queue:${RESET}   systemctl status ${QUEUE_UNIT}  |  journalctl -u ${QUEUE_UNIT} -f"
    echo -e "  ${BOLD}Cron:${RESET}    sudo crontab -u ${APP_USER} -l"
    echo
    echo -e "  ${BOLD}${YELLOW}Save these credentials now — they are not stored on disk.${RESET}"
    echo
    echo "    Domain:   ${DOMAIN}"
    echo "    App dir:  ${APP_DIR}"
    echo "    App user: ${APP_USER}"
    echo "    MariaDB:  ${DB_NAME} / ${DB_USER} / ${DB_PASSWORD}"
    echo "    Backup:   ${BACKUP_ARCHIVE_PASSWORD}"
    echo "    Mail:     ${MAIL_HOST:-(log driver)}${MAIL_HOST:+:${MAIL_PORT} / ${MAIL_USER}}"
    [[ -n "$OWNER_EMAIL" ]] && echo "    Owner:    ${OWNER_EMAIL} / ${OWNER_PASSWORD}"
fi

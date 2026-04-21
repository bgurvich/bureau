#!/usr/bin/env bash
# =============================================================================
# Bureau — Production installer (env + nginx + certbot + queue + scheduler)
#
# Adapted from ~/nfp/scripts/deploy/install.sh. Complements the package/app
# bootstrap scripts:
#   1. sudo bash scripts/deploy/install-packages.sh   (OS packages, once)
#   2. bash scripts/deploy/setup.sh                   (composer/npm/artisan)
#   3. sudo bash scripts/deploy/install.sh            ← this script
#   4. bash scripts/deploy/deploy.sh                  (every release)
#
# Usage:
#   sudo bash install.sh                              # all steps
#   sudo bash install.sh --only nginx                 # single step
#   sudo bash install.sh --only nginx ssl             # multiple steps
#   sudo bash install.sh --skip ssl                   # all except ssl
#   sudo bash install.sh --force nginx                # re-run nginx only
#   sudo bash install.sh --force all                  # re-run everything
#
# Completed steps are tracked in /var/lib/bureau-install/ and auto-skipped
# on re-run. Use --force <step…> to re-run finished steps.
#
# Available steps:  env  nginx  ssl|certbot  queue-worker  scheduler
# =============================================================================
set -euo pipefail
# Default-deny bits for anything this script creates. Files that must be
# world-readable (nginx vhost, LE cert) get explicit chmod below.
umask 077
export DEBIAN_FRONTEND=noninteractive

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

info()    { echo -e "${CYAN}[info]${RESET}  $*"; }
success() { echo -e "${GREEN}[ok]${RESET}    $*"; }
warn()    { echo -e "${YELLOW}[warn]${RESET}  $*"; }
die()     { echo -e "${RED}[error]${RESET} $*" >&2; exit 1; }
step()    { echo -e "\n${BOLD}━━━  $*  ━━━${RESET}"; }

[[ $EUID -ne 0 ]] && die "Run as root: sudo bash install.sh"

# ── Parse flags ──────────────────────────────────────────────────────────────
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
            sed -n '2,25p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
    esac
    shift
done

# ── Completion markers ───────────────────────────────────────────────────────
MARK_DIR="/var/lib/bureau-install"
mkdir -p "$MARK_DIR"

already_done() { [[ -f "${MARK_DIR}/$1.done" ]]; }
mark_done()    { date -u +%FT%TZ > "${MARK_DIR}/$1.done"; }
mark_clear()   { rm -f "${MARK_DIR}/$1.done"; }

is_forced() {
    local name="$1"
    [[ " ${FORCE_STEPS[*]} " == *" all "* ]] && return 0
    [[ " ${FORCE_STEPS[*]} " == *" $name "* ]]
}

# certbot is an alias for ssl
should_run() {
    local name="$1"
    [[ "$name" == "certbot" ]] && name="ssl"
    [[ " ${SKIP_STEPS[*]} " == *" $name "* ]] && return 1
    [[ " ${SKIP_STEPS[*]} " == *" certbot "* && "$name" == "ssl" ]] && return 1

    # `--force X` without `all` narrows the run to those steps only.
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
# Detect the repo root — the script lives at $REPO/scripts/deploy/install.sh.
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
DB_PASSWORD="${DB_PASSWORD:-}"
MAIL_HOST="${MAIL_HOST:-}"
MAIL_PORT="${MAIL_PORT:-}"
MAIL_USER="${MAIL_USER:-}"
MAIL_PASS="${MAIL_PASS:-}"
ADMIN_EMAIL="${ADMIN_EMAIL:-}"

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

# Read a value from the project .env (strips surrounding quotes, inline comments).
env_read() {
    local key="$1" env="${APP_DIR}/.env"
    [[ -f "$env" ]] || return 0
    awk -F= -v k="$key" '
        $0 ~ "^"k"=" {
            sub("^"k"=", "", $0)
            sub(/ +#.*$/, "", $0)
            gsub(/^"|"$|^'\''|'\''$/, "", $0)
            print
            exit
        }
    ' "$env"
}

# Update-or-append a key in the project .env. Pure bash so secrets don't appear
# in `ps` (no `sed -i "s|key|${secret}|"`), and values with sed metacharacters
# (`|`, `&`, `\`) don't corrupt the file.
env_set() {
    local key="$1" value="$2" env="${APP_DIR}/.env"
    [[ -f "$env" ]] || { warn "env_set: ${env} missing"; return 1; }
    local tmp
    tmp="$(mktemp "${env}.XXXXXX")"
    chmod --reference="$env" "$tmp" 2>/dev/null || chmod 600 "$tmp"
    chown --reference="$env" "$tmp" 2>/dev/null || true
    local line found=0
    while IFS= read -r line || [[ -n "$line" ]]; do
        if [[ "$line" == "${key}="* ]]; then
            printf '%s=%s\n' "$key" "$value" >> "$tmp"
            found=1
        else
            printf '%s\n' "$line" >> "$tmp"
        fi
    done < "$env"
    (( found )) || printf '%s=%s\n' "$key" "$value" >> "$tmp"
    mv "$tmp" "$env"
}

prompt APP_DIR "Application directory" "$APP_DIR"
prompt DOMAIN  "Domain"                 "$DOMAIN"

if should_run env; then
    _db_default="$(env_read DB_PASSWORD)"
    [[ -n "$_db_default" ]] || _db_default="$(openssl rand -base64 18)"
    prompt DB_PASSWORD "MariaDB password for 'bureau' user" "$_db_default" secret
    prompt MAIL_HOST   "SMTP host (blank → mail log driver)" ""
    prompt MAIL_PORT   "SMTP port"                           "587"
    prompt MAIL_USER   "SMTP username"                       "noreply@${DOMAIN}"
    prompt MAIL_PASS   "SMTP password"                       "" secret

    # Off-site backup archive password — auto-generate when blank. Must be
    # stable across runs (once a backup is encrypted with key X, that key must
    # exist to restore), so preserve any value already in .env.
    _backup_pw_default="$(env_read BACKUP_ARCHIVE_PASSWORD)"
    [[ -n "$_backup_pw_default" ]] || _backup_pw_default="$(openssl rand -base64 32 | tr -d '/=+' | head -c 40)"
    prompt BACKUP_ARCHIVE_PASSWORD "Backup archive encryption password (AES-256 inside the zip)" "$_backup_pw_default" secret
fi
if should_run ssl; then
    prompt ADMIN_EMAIL "Admin / Let's Encrypt email" "admin@${DOMAIN}"
fi

echo
info "App dir:  $APP_DIR"
info "Domain:   $DOMAIN"
info "App user: $APP_USER"
[[ ${#ONLY_STEPS[@]} -gt 0 ]] && info "Only:     ${ONLY_STEPS[*]}" \
    || { [[ ${#SKIP_STEPS[@]} -gt 0 ]] && info "Skip:     ${SKIP_STEPS[*]}" \
        || info "Steps:    env nginx ssl"; }
echo
read -rp "  Proceed? (y/N) " confirm
[[ "$confirm" =~ ^[Yy]$ ]] || die "Aborted."

# =============================================================================
# STEPS
# =============================================================================

step_env() {
    step "Environment file"
    local env="${APP_DIR}/.env"
    [[ -f "${APP_DIR}/.env.example" ]] || die ".env.example missing at ${APP_DIR}"
    [[ -f "$env" ]] || cp "${APP_DIR}/.env.example" "$env"

    # Generate APP_KEY if missing — the app won't boot without one.
    local current_key
    current_key=$(env_read APP_KEY)
    if [[ -z "$current_key" || "$current_key" == "base64:" || "$current_key" == "base64:generate-me" ]]; then
        env_set APP_KEY "base64:$(openssl rand -base64 32)"
        info "Generated APP_KEY."
    fi

    # Fall back to the log mailer when no SMTP host was provided so mail calls
    # don't crash before the operator wires up Postmark / Amazon SES / Fastmail.
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

    # Bureau defaults to database-backed sessions/cache/queue (matches
    # .env.example). Operator can flip to Redis once Redis is provisioned —
    # not in scope here. Single-host deployment → SESSION_DOMAIN=${DOMAIN}.
    env_set SESSION_DRIVER       "database"
    env_set SESSION_LIFETIME     "120"
    env_set SESSION_ENCRYPT      "true"
    env_set SESSION_PATH         "/"
    env_set SESSION_DOMAIN       "$DOMAIN"
    env_set SESSION_SECURE_COOKIE "true"
    env_set SESSION_SAME_SITE    "lax"
    env_set CACHE_STORE          "database"
    env_set QUEUE_CONNECTION     "database"
    env_set BROADCAST_CONNECTION "log"
    env_set FILESYSTEM_DISK      "local"

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

    # Backup archive password — always populated, even when no off-site disk
    # is configured yet. Makes future "enable B2" a one-line AWS_BUCKET edit
    # without having to regenerate past backups.
    env_set BACKUP_ARCHIVE_PASSWORD "$BACKUP_ARCHIVE_PASSWORD"

    # .env carries APP_KEY, DB creds, OAuth secrets → owner:www-data, 640.
    # www-data needs read access so nginx-launched PHP-FPM (and its worker
    # pool) can boot. If the `bureau` user is missing, fall back to root.
    local owner_spec="root:www-data"
    if id "$APP_USER" &>/dev/null; then
        owner_spec="${APP_USER}:www-data"
    else
        warn "user '${APP_USER}' missing — leaving .env owned by root:www-data"
    fi
    chown "$owner_spec" "$env"
    chmod 640 "$env"

    # Flush opcache-cached config when a prior run populated
    # bootstrap/cache/config.php; otherwise FPM serves stale values.
    if [[ -f "${APP_DIR}/bootstrap/cache/config.php" ]]; then
        if id "$APP_USER" &>/dev/null; then
            sudo -u "$APP_USER" -H php "${APP_DIR}/artisan" config:cache --quiet || true
        else
            php "${APP_DIR}/artisan" config:cache --quiet || true
        fi
        systemctl reload "php${PHP_VER}-fpm" 2>/dev/null || true
        info "Rebuilt config cache and reloaded PHP-FPM."
    fi

    success ".env configured (mailer=${mailer})."
}

step_nginx() {
    step "Nginx configuration"
    command -v nginx &>/dev/null \
        || die "nginx not installed — run scripts/deploy/install-packages.sh first"

    local src="${APP_DIR}/scripts/deploy/nginx-bureau.conf"
    local dest="/etc/nginx/sites-available/bureau.conf"
    [[ -f "$src" ]] || die "template missing: $src"

    # Substitute placeholders (pipe delimiter so paths with / don't break sed).
    sed -e "s|__DOMAIN__|${DOMAIN}|g" \
        -e "s|__APP_DIR__|${APP_DIR}|g" \
        -e "s|__PHP_VER__|${PHP_VER}|g" \
        "$src" > "$dest"
    chmod 644 "$dest"

    # If a Let's Encrypt cert already exists for this domain, wire the vhost
    # to it so re-running `--force nginx` doesn't revert to the self-signed
    # placeholder and break TLS until step_ssl runs again.
    local le_cert="/etc/letsencrypt/live/${DOMAIN}"
    if [[ -f "${le_cert}/fullchain.pem" ]]; then
        sed -i "s|ssl_certificate .*|ssl_certificate     ${le_cert}/fullchain.pem;|" "$dest"
        sed -i "s|ssl_certificate_key .*|ssl_certificate_key ${le_cert}/privkey.pem;|" "$dest"
        info "Pointed vhost at existing Let's Encrypt cert."
    elif [[ ! -f "/etc/ssl/bureau/fullchain.pem" ]]; then
        # Short-lived self-signed placeholder lets nginx boot before step_ssl
        # issues the real cert. 1-day validity keeps operators honest if SSL
        # provisioning fails silently.
        mkdir -p /etc/ssl/bureau
        openssl req -x509 -nodes -days 1 -newkey rsa:2048 \
            -keyout /etc/ssl/bureau/privkey.pem \
            -out    /etc/ssl/bureau/fullchain.pem \
            -subj   "/CN=${DOMAIN}" 2>/dev/null
        chmod 640 /etc/ssl/bureau/privkey.pem
        info "Self-signed placeholder cert issued (valid 24h)."
    fi

    # ACME webroot directory for HTTP-01 renewals.
    mkdir -p /var/www/certbot
    chown www-data:www-data /var/www/certbot

    ln -sfn "$dest" /etc/nginx/sites-enabled/bureau.conf
    rm -f /etc/nginx/sites-enabled/default
    nginx -t
    systemctl enable nginx
    systemctl reload nginx
    success "Nginx vhost installed and reloaded."
}

step_ssl() {
    step "SSL — Let's Encrypt"
    command -v certbot &>/dev/null || apt-get install -y -qq certbot

    mkdir -p /var/www/certbot
    chown www-data:www-data /var/www/certbot

    # Webroot flow so nginx keeps serving traffic during issuance AND renewal —
    # no `certbot --standalone` + `systemctl stop nginx` juggling. The vhost's
    # /.well-known/acme-challenge/ location already routes to /var/www/certbot.
    if ! certbot certonly --webroot -w /var/www/certbot \
            -d "${DOMAIN}" -d "www.${DOMAIN}" \
            --email "$ADMIN_EMAIL" --agree-tos --non-interactive --keep-until-expiring; then
        warn "Multi-domain cert failed (DNS for www.${DOMAIN} missing?) — trying apex only..."
        certbot certonly --webroot -w /var/www/certbot \
            -d "${DOMAIN}" \
            --email "$ADMIN_EMAIL" --agree-tos --non-interactive --keep-until-expiring
    fi

    # Wire the vhost to the issued cert (idempotent — sed replaces in place).
    local cert="/etc/letsencrypt/live/${DOMAIN}"
    sed -i "s|ssl_certificate .*|ssl_certificate     ${cert}/fullchain.pem;|" \
        /etc/nginx/sites-available/bureau.conf
    sed -i "s|ssl_certificate_key .*|ssl_certificate_key ${cert}/privkey.pem;|" \
        /etc/nginx/sites-available/bureau.conf

    nginx -t && systemctl reload nginx

    # Renewal hooks — webroot flow only needs a post-renewal reload.
    mkdir -p /etc/letsencrypt/renewal-hooks/deploy
    cat > /etc/letsencrypt/renewal-hooks/deploy/nginx-reload.sh <<'HOOK'
#!/usr/bin/env bash
systemctl reload nginx
HOOK
    chmod +x /etc/letsencrypt/renewal-hooks/deploy/nginx-reload.sh

    # Surface the renewal timer state — silent failures here bite 60 days later.
    if systemctl is-active --quiet snap.certbot.renew.timer 2>/dev/null \
        || systemctl is-active --quiet certbot.timer 2>/dev/null; then
        info "Certbot renewal timer is active."
    else
        warn "Certbot renewal timer not active — check: systemctl list-timers | grep cert"
    fi

    success "SSL installed; auto-renewal hook configured."
}

step_queue_worker() {
    step "Queue worker — systemd unit"
    # Plain `queue:work` instead of Horizon — Bureau is single-tenant / low-volume
    # (OCR scans, reminder emails, recurring projections, backup archival). Horizon
    # forces a Redis dependency and adds dashboard/failed-job UI we don't need at
    # this scale. Graduate to Horizon if load justifies it (SaaS, live bank feeds).
    #
    # --max-time=3600: workers recycle hourly so memory-leaky jobs (OCR pulls large
    #   images into PHP-GD) don't accumulate RSS indefinitely.
    # --tries=3 --backoff=30: three attempts with 30s backoff covers the common
    #   transient failures (Tesseract binary blocked by AppArmor briefly, mail
    #   relay 4xx). Terminal failures land in failed_jobs via Laravel's retry wrapper.
    # Restart=always + RestartSec=5: systemd respawns on queue:restart signal
    #   (deploy.sh issues this) or on crash.
    local unit="/etc/systemd/system/bureau-queue.service"
    cat > "$unit" <<UNIT
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
    chmod 644 "$unit"
    systemctl daemon-reload
    systemctl enable bureau-queue
    systemctl restart bureau-queue
    success "bureau-queue.service enabled and running (journalctl -u bureau-queue -f)."
}

step_scheduler() {
    step "Scheduler — cron"
    # Drives: recurring:project (03:00), media:rescan (on schedule), backup:run
    # (03:30) + clean (02:30) + monitor (12:00), snapshots:rollup (2nd@04:00),
    # reminders:* MVP (every 15 min), weekly-review digest, etc. See
    # routes/console.php for the full schedule.
    #
    # The scheduler must run as APP_USER so file writes (logs, backup archives,
    # config cache) keep the right ownership. Running as root poisons file
    # permissions and breaks subsequent deploys.
    id "$APP_USER" &>/dev/null \
        || die "user '${APP_USER}' not found — create it first (install-packages step_app_user equivalent)"

    local line="* * * * * cd ${APP_DIR} && /usr/bin/php ${APP_DIR}/artisan schedule:run >> /dev/null 2>&1"
    # Idempotent: strip any previous schedule:run line, then append the canonical one.
    (crontab -u "$APP_USER" -l 2>/dev/null | grep -v "artisan schedule:run"; echo "$line") \
        | crontab -u "$APP_USER" -
    success "schedule:run cron installed for user '${APP_USER}' (minutely)."
}

# =============================================================================
# RUN
# =============================================================================
run_step env          step_env
run_step nginx        step_nginx
run_step ssl          step_ssl
run_step queue-worker step_queue_worker
run_step scheduler    step_scheduler

echo
echo -e "${GREEN}${BOLD}━━━  Done  ━━━${RESET}"
echo
if [[ ${#ONLY_STEPS[@]} -eq 0 ]]; then
    echo -e "  ${BOLD}App:${RESET}     https://${DOMAIN}"
    echo -e "  ${BOLD}Health:${RESET}  https://${DOMAIN}/up"
    echo -e "  ${BOLD}Nginx:${RESET}   /etc/nginx/sites-available/bureau.conf"
    echo -e "  ${BOLD}Cert:${RESET}    /etc/letsencrypt/live/${DOMAIN}/"
    echo -e "  ${BOLD}Queue:${RESET}   systemctl status bureau-queue  |  journalctl -u bureau-queue -f"
    echo -e "  ${BOLD}Cron:${RESET}    crontab -u ${APP_USER} -l"
    echo
    echo -e "  ${BOLD}${YELLOW}Save these credentials now — they are not stored on disk.${RESET}"
    echo
    echo "    Domain:   ${DOMAIN}"
    echo "    App dir:  ${APP_DIR}"
    echo "    App user: ${APP_USER}"
    echo "    MariaDB:  bureau / bureau / ${DB_PASSWORD}"
    echo "    Mail:     ${MAIL_HOST:-(log driver)}${MAIL_HOST:+:${MAIL_PORT} / ${MAIL_USER}}"
fi

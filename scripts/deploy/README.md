# Deploy scripts

Four entry points, all idempotent and safe to re-run.

Production flow (first-time, in order):
1. `install-packages.sh` — OS toolchain (nginx, php, mariadb, tesseract…)
2. `setup.sh` — app bootstrap (composer, npm, storage, migrate, seed owner)
3. `install.sh` — production wiring (`.env`, nginx vhost, Let's Encrypt)
4. `deploy.sh` — every release thereafter

## First-time server provisioning

```bash
sudo bash scripts/deploy/install-packages.sh
```

Installs the OS-level toolchain:
- nginx, mariadb, redis
- PHP 8.3 (Ondrej PPA) + fpm/cli/mysql/redis/mbstring/xml/curl/zip/bcmath/intl/gd/opcache
- Composer, Node 22 via NVM (symlinked into `/usr/local/bin`)
- Tesseract + English traineddata (drives `App\Jobs\OcrMedia`)
- ImageMagick (PNG generation, media thumbnailing)
- Poppler-utils (pdftotext, PDF-OCR fallback)

ImageMagick's default policy blocks the PDF coder (a real attack surface — ImageTragick and kin). Bureau uses Poppler's `pdftotext` for PDFs, not ImageMagick, so the lockdown stays.

Override versions at call time:
```bash
sudo PHP_VER=8.3 NODE_VER=22 bash scripts/deploy/install-packages.sh
```

## First-time app setup

```bash
bash scripts/deploy/setup.sh
```

Prepares a cloned checkout:
1. Verifies `php / composer / node / npm` present.
2. Copies `.env.example → .env` on first run.
3. `php artisan key:generate` if `APP_KEY` is empty.
4. `composer install` + `npm ci` + `npm run build`.
5. Creates `storage/framework/*` subdirs; `chmod -R ug+rw storage bootstrap/cache`.
6. `php artisan storage:link`.
7. `php artisan migrate --force`.
8. `php artisan db:seed --force` — **only if the users table is empty** (never clobbers existing data).

## Production wiring (env + nginx + certbot)

```bash
sudo bash scripts/deploy/install.sh
```

Stepped, marker-tracked installer (`/var/lib/bureau-install/*.done`). Adapted from `~/nfp/scripts/deploy/install.sh`. Five steps, all re-runnable:

1. **env** — populates `.env` with production values (APP_ENV=production, APP_URL=`https://bureau.homes`, SESSION_SECURE_COOKIE=true, SESSION_DOMAIN, DB/mail creds). Generates `APP_KEY` if empty. Owner → `bureau:www-data`, mode `640`.
2. **nginx** — substitutes `nginx-bureau.conf` placeholders (`__DOMAIN__`, `__APP_DIR__`, `__PHP_VER__`) into `/etc/nginx/sites-available/bureau.conf`, symlinks into `sites-enabled`, drops a 24h self-signed placeholder cert so nginx boots before the ACME step, tests + reloads.
3. **ssl** (alias: `certbot`) — issues `bureau.homes` + `www.bureau.homes` via `certbot --webroot -w /var/www/certbot`, rewrites vhost `ssl_certificate` paths, drops a post-renewal reload hook.
4. **queue-worker** — installs `/etc/systemd/system/bureau-queue.service` running `php artisan queue:work --sleep=3 --tries=3 --backoff=30 --max-time=3600`. Plain `queue:work` rather than Horizon (single-tenant, low volume — Horizon forces Redis and adds dashboard UI the app doesn't need). `deploy.sh`'s `queue:restart` drains and respawns workers on each release.
5. **scheduler** — cron `* * * * * php artisan schedule:run` for user `bureau`. Drives `recurring:project`, `media:rescan`, `backup:run/clean/monitor`, `snapshots:rollup`, reminders delivery, weekly digests. Idempotent — re-runs strip the old line before appending.

```bash
sudo bash scripts/deploy/install.sh --only nginx          # single step
sudo bash scripts/deploy/install.sh --only nginx ssl      # multiple
sudo bash scripts/deploy/install.sh --skip ssl            # all except ssl
sudo bash scripts/deploy/install.sh --force nginx         # re-run nginx only
sudo bash scripts/deploy/install.sh --force all           # re-run everything
```

Prompts for domain (default `bureau.homes`), DB password, SMTP creds (blank → mail log driver), and Let's Encrypt admin email. Credentials print once at the end and aren't written to disk.

Webroot ACME flow means no nginx restart during cert renewal — certbot's systemd timer drops new certs into `/etc/letsencrypt/live/`, the deploy hook reloads nginx, done.

## Production release

```bash
bash scripts/deploy/deploy.sh [--skip-build]
```

Runs from the repo root on the deploy target.

Pipeline:
1. `git pull`; exits cleanly when already up to date.
2. Hard security gates: `.env` exists, `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` non-empty, `storage/` + `bootstrap/cache/` writable, no `phpinfo()` under `public/`.
3. `php artisan down` with retry headers.
4. `composer install --no-dev --optimize-autoloader` + `npm ci && npm run build`.
5. `mysqldump | gzip → storage/backups/pre-migrate-<timestamp>.sql.gz`.
6. `php artisan migrate --force`.
7. `php artisan optimize` (config + routes + views + events cached).
8. `chmod -R ug+rw storage bootstrap/cache`.
9. Reloads detected php-fpm systemd unit to clear OPcache.
10. `php artisan queue:restart` so OCR / future queued jobs pick up new code.
11. `composer audit` + `npm audit --omit=dev` (warn-only, never block).
12. `php artisan up`.
13. Health check: `curl -fsS ${APP_URL}/up` (the Laravel 11+ built-in endpoint registered in `bootstrap/app.php`).

The trap on `ERR` auto-calls `php artisan up` if anything fails before step 12, so aborted deploys don't leave the app stuck in maintenance mode.

## ZAP (passive security scan)

Separate from deploy. From `scripts/`:
```bash
./scripts/setup-zap.sh                # one-time, pulls the ZAP Docker image + writes automation config
./scripts/run-zap.sh baseline         # passive only (~2 min)
./scripts/run-zap.sh baseline --ci    # fail the run on High/Medium findings
./scripts/run-zap.sh full             # active attacks (~10-30 min)
./scripts/run-zap.sh auth             # authenticated crawl + active (~15-45 min)
```

Reports land in `zap/` (gitignored). Start the app first: `php artisan serve --host=0.0.0.0 --port=8000`.

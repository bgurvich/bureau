# Deploy scripts

Three entry points, all idempotent and safe to re-run.

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

ImageMagick's default policy blocks PDF rasterization; this script relaxes it so the future `pdftoppm → tesseract` pipeline works.

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

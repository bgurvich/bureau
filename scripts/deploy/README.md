# Deploy scripts

Two scripts. One-time setup + every-release updates.

## First-time server setup

```bash
bash scripts/deploy/install.sh
```

Runs as the **current user** (not root); escalates via `sudo` where needed. `sudo` credential is kept warm for the length of the run.

Stepped, marker-tracked (`/var/lib/bureau-install/*.done`), safe to re-run. Each step prompts for only the inputs it needs — `--only nginx` won't ask for a DB password it'll never use.

| Step | What it does |
|---|---|
| `packages` | PHP 8.3, MariaDB, Redis, nginx, Node (via NVM at `/opt/nvm`), Composer, Certbot, Tesseract, ImageMagick, Poppler, p7zip-full. |
| `mariadb` | Creates `bureau` DB + user with the chosen password; syncs DB creds into `.env` if it already exists. |
| `app-user` | Creates the `bureau` OS user. |
| `permissions` | `chown -R bureau:www-data` on the repo; `chmod o+x` up the ancestor chain so www-data can traverse to `public/`; `ug+rwX,o-rwx` on `storage/` + `bootstrap/cache/`. |
| `env` | Writes `.env` (APP_ENV=production, APP_URL=`https://bureau.homes`, DB creds, session/cache/queue drivers, mail, backup password); generates APP_KEY if empty; owner `bureau:www-data`, mode `640`. |
| `composer` | `composer install --no-dev --optimize-autoloader` as `bureau`. |
| `frontend` | `npm ci && npm run build` as `bureau`. |
| `artisan` | `migrate --force`; `db:seed --force` on empty DB; caches config/view/event; reloads php-fpm. |
| `storage-link` | `artisan storage:link`. |
| `nginx` | Substitutes placeholders in `nginx-bureau.conf` → `/etc/nginx/sites-available/bureau.conf`; issues a 24h self-signed placeholder cert so nginx boots; tests + reloads. |
| `ssl` (alias `certbot`) | Issues `bureau.homes` + `www.bureau.homes` via `certbot --webroot`; drops a post-renewal reload hook. |
| `queue-worker` | Installs `bureau-queue.service` systemd unit running `queue:work --max-time=3600`. |
| `scheduler` | Minutely cron for `artisan schedule:run` as `bureau`. |
| `firewall` | UFW allow SSH + Nginx Full. **Off by default** — opt-in via `--only firewall`. |

### Flag reference

```bash
bash scripts/deploy/install.sh                       # all steps (firewall is opt-in)
bash scripts/deploy/install.sh --only nginx ssl      # subset
bash scripts/deploy/install.sh --skip composer       # all except composer
bash scripts/deploy/install.sh --force nginx         # re-run just nginx
bash scripts/deploy/install.sh --force all           # re-run every step
```

### Bootstrap order gotcha

The repo has to exist on the box before you run this script (obviously — you're running it from the repo). Typical flow on a clean VPS:

```bash
# As your ordinary user (e.g. moshe / ubuntu):
sudo apt-get install -y git
sudo mkdir -p /var/www/bureau
sudo chown $USER: /var/www/bureau
git clone git@github.com:bgurvich/bureau.git /var/www/bureau
cd /var/www/bureau
bash scripts/deploy/install.sh
```

The `permissions` step will re-chown to `bureau:www-data` once the OS user exists.

## Production release

```bash
bash scripts/deploy/deploy.sh [--skip-build]
```

Every release thereafter. Pipeline:

1. `git pull`; exits cleanly when already up to date.
2. Hard security gates: `.env` exists, `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` non-empty, `storage/` + `bootstrap/cache/` writable, no `phpinfo()` under `public/`.
3. `php artisan down` with retry headers.
4. `composer install --no-dev --optimize-autoloader` + `npm ci && npm run build`.
5. `mysqldump | gzip → storage/backups/pre-migrate-<timestamp>.sql.gz`.
6. `php artisan migrate --force`.
7. `php artisan optimize` (config + routes + views + events cached).
8. `chmod -R ug+rwX,o-rwx storage bootstrap/cache`.
9. Reloads the detected php-fpm systemd unit to clear OPcache.
10. `php artisan queue:restart` so OCR / future queued jobs pick up new code.
11. `composer audit` + `npm audit --omit=dev` (warn-only).
12. `php artisan up`.
13. Health check: `curl -fsS ${APP_URL}/up`.

The `ERR` trap auto-calls `php artisan up` if anything fails before step 12, so aborted deploys don't leave the app stuck in maintenance mode.

## ZAP (passive security scan)

Separate from deploy. From the repo root:

```bash
./scripts/setup-zap.sh                # one-time, pulls the ZAP Docker image + writes automation config
./scripts/run-zap.sh baseline         # passive only (~2 min)
./scripts/run-zap.sh baseline --ci    # fail the run on High/Medium findings
./scripts/run-zap.sh full             # active attacks (~10-30 min)
./scripts/run-zap.sh auth             # authenticated crawl + active (~15-45 min)
```

Reports land in `zap/` (gitignored). Start the app first: `php artisan serve --host=0.0.0.0 --port=8000`.

You can also run ZAP as part of `scripts/test-all.sh --zap`.

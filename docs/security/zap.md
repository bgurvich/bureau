# OWASP ZAP scanning — runbook

ZAP (Zed Attack Proxy) is Secretaire's web-application security scanner. This doc
covers three modes — pick the one that matches what you want to learn.

## 0. When to run which mode

| Mode | Risk | What it finds | How long |
|------|------|---------------|----------|
| Baseline (passive) | None. Read-only, no attack payloads. | Missing/weak headers, cookie flags, exposed tech, basic misconfig. | 2–5 min |
| Full active | **High.** Sends SQLi/XSS/XXE/traversal payloads. Creates garbage rows, fires any wired webhooks. | Real injection bugs, reflected XSS, open redirects, auth bypass. | 30 min – 4 h |
| Authenticated (baseline or full) | Same as above + covers routes behind `auth`. | Everything the guest scan misses. | Scales with route count |

Rule of thumb: run the baseline locally before every significant change. Run
the full active scan against a **throwaway** instance before every release or
when anything touching auth / auth-adjacent controllers lands.

## 1. Baseline (passive, local)

```sh
composer zap
```

The script starts `php artisan serve --host=0.0.0.0 --port=8090` in the
background, runs `ghcr.io/zaproxy/zaproxy:stable` via Docker, and writes
`storage/zap/baseline-<timestamp>.{html,json}`.

Target a different URL or port:

```sh
ZAP_TARGET=http://localhost:8000 composer zap
ZAP_PORT=9001 composer zap
ZAP_NO_SERVE=1 ZAP_TARGET=https://secretaire.example composer zap
```

On Linux the script wires `host.docker.internal` to the host gateway via
`--add-host` so ZAP running inside Docker can reach your local serve process.

## 2. Full active scan (throwaway DB)

**Do not run against dev data you care about.** Active scans mutate state:
fake accounts, bogus rows, spam on any wired webhook, potentially queue-job
flooding.

```sh
# 1. Spin up a separate DB
export DB_DATABASE=secretaire_zap
mysql -uroot -e "DROP DATABASE IF EXISTS secretaire_zap; CREATE DATABASE secretaire_zap;"
php artisan migrate --force
php artisan db:seed   # if you have seeders that make the surface interesting

# 2. Start the app
php artisan serve --host=0.0.0.0 --port=8090 &

# 3. Run the full scan
docker run --rm \
  --add-host=host.docker.internal:host-gateway \
  -v "$(pwd)/storage/zap:/zap/wrk/:rw" \
  -t ghcr.io/zaproxy/zaproxy:stable \
  zap-full-scan.py -t http://host.docker.internal:8090 \
    -r full-$(date +%Y%m%d-%H%M%S).html
```

After: drop `secretaire_zap`, kill the serve process, and `composer test:refresh`
before using dev again.

## 3. Authenticated scan

ZAP's context file teaches it how to log in + what an authenticated page
looks like (so it can detect session expiry and re-auth).

### Easiest path: reusable session cookie

1. Log into Secretaire in your browser, grab the `secretaire_session` cookie value
   from DevTools → Application → Cookies.
2. Run ZAP with the cookie injected on every request:

```sh
docker run --rm \
  --add-host=host.docker.internal:host-gateway \
  -v "$(pwd)/storage/zap:/zap/wrk/:rw" \
  -t ghcr.io/zaproxy/zaproxy:stable \
  zap-baseline.py -t http://host.docker.internal:8090 \
    -z "-config replacer.full_list(0).description=authcookie \
        -config replacer.full_list(0).enabled=true \
        -config replacer.full_list(0).matchtype=REQ_HEADER \
        -config replacer.full_list(0).matchstr=Cookie \
        -config replacer.full_list(0).regex=false \
        -config replacer.full_list(0).replacement='secretaire_session=<your-cookie-value>'" \
    -r auth-baseline-$(date +%Y%m%d-%H%M%S).html
```

The session lifetime is 2h by default; if the scan runs longer, the second
half will see 302s back to `/login` and under-report.

### Robust path: ZAP context file + login script

For long / repeatable runs, build a ZAP context that handles login via a
dedicated script. `docs/security/zap-context.example` is a starting template —
copy it, fill in your email, and point ZAP's `-n <context>` at it. Out of
scope for this doc; see https://www.zaproxy.org/docs/authentication/ .

## 4. Reading the report

Every finding has a risk level (Informational → Low → Medium → High). Focus
on Medium+ first. For each:

1. **Reproduce** — ZAP's report has the exact request/response. Paste the
   request into `curl` or a browser; confirm it still fails.
2. **Diagnose** — is this a real bug or a false positive given how Secretaire
   uses the feature? E.g., missing `X-Frame-Options` is a real bug; missing
   `X-Content-Type-Options` on a route that only serves JSON is cosmetic.
3. **Fix the root cause** — add the header in middleware, set the cookie
   flag in config, patch the reflected input, etc. Don't just silence in
   `.github/zap-rules.tsv` unless the finding is a confirmed false positive.
4. **Regress** — re-run `composer zap` and confirm the finding is gone.

## 5a. Two remaining Medium findings are intentional

After header/nonce hardening, ZAP still reports two Medium CSP findings:

- `script-src 'unsafe-eval'` — Livewire + Alpine evaluate component
  expressions via `new Function()`. Removing requires swapping to Livewire's
  CSP bundle + Alpine's CSP build, both of which restrict expression
  syntax. That's a multi-component rewrite, not a quick fix.
- `style-src 'unsafe-inline'` — Livewire emits inline styles for
  `wire:loading`, `x-cloak`, `x-show`. Same Livewire-CSP-bundle story.

Both are documented tradeoffs. The `script-src 'unsafe-inline'` that
previously appeared is gone — our nonce-based CSP (via the `CspNonce`
middleware and `SecurityHeaders`) emits a per-request nonce on every
script tag from Vite, Livewire, and the inline theme-resolver scripts.

## 5. Tuning ZAP for Secretaire

`.github/zap-rules.tsv` lives next to the GitHub Actions workflow and
downgrades known-false-positive rules so they don't nag us forever. The
format is `<ruleId>\t<threshold>\t<comment>`. Always include a `<comment>`
explaining why the default is wrong — a silent IGNORE rots into a real bug
hiding in plain sight.

## 6. Known false positives on `php artisan serve`

Three findings flagged by the baseline scan when running against the dev
server do **not** appear when Secretaire sits behind nginx in production:

- `X-Content-Type-Options` missing on `/build/assets/*` + `/robots.txt`
- `Permissions-Policy` missing on `/build/assets/*`
- `Server Leaks Information via "X-Powered-By"` (5×)

The cause is the same: PHP's built-in dev server serves static files
directly and never passes them through Laravel middleware, so
`SecurityHeaders` doesn't get a chance to stamp the headers. In
production these come from the web server, not the app. A minimal nginx
snippet that covers them:

```nginx
# /etc/nginx/conf.d/secretaire-headers.conf
add_header X-Content-Type-Options       "nosniff"                                always;
add_header X-Frame-Options              "DENY"                                   always;
add_header Referrer-Policy              "strict-origin-when-cross-origin"        always;
add_header Permissions-Policy           "camera=(), microphone=(), geolocation=(), payment=(), usb=()" always;
add_header Strict-Transport-Security    "max-age=15552000; includeSubDomains"    always;
fastcgi_hide_header X-Powered-By;
server_tokens off;
```

The `always` flag matters — without it nginx skips the header on 4xx/5xx
responses. `fastcgi_hide_header` drops what PHP-FPM adds upstream;
`server_tokens off` stops nginx advertising its own version.

## 7. CI

`.github/workflows/zap.yml` runs the baseline scan:
- On every PR that touches app/config/routes/views.
- Weekly against `main` (Mondays 06:00 UTC).
- On demand via `workflow_dispatch`.

The CI job uses a fresh `secretaire` MariaDB service, so findings reflect a
clean-install instance (no dev data leaking into the report). Results are
uploaded as the `zap-baseline-report` artifact — download it from the
Actions tab.

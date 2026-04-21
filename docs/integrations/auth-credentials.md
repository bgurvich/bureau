# Integration auth credentials

How to provision the keys, secrets, and OAuth clients Bureau's integrations expect. Every entry here is for the **production** tenant — use a separate OAuth client in dev with `http://localhost` redirects, or the provider's sandbox mode, so rotation is safe.

## Credential storage model

Two layers:

1. **`.env`** — process-scoped secrets Laravel needs to boot (`GOOGLE_CLIENT_ID`, `POSTMARK_WEBHOOK_PASSWORD`, `AWS_SECRET_ACCESS_KEY`). Mode `640`, owner `bureau:www-data`. Never committed. Rotation = edit `.env` + `php artisan config:clear`.
2. **`integrations` table** — per-household encrypted credentials for interactive integrations that are provisioned at runtime (Gmail OAuth token, Fastmail app password, PayPal REST credentials). `artisan integrations:connect-*` commands write to it; encryption is Laravel's application-level `Crypt::encryptString`.

Wire keys only into the layer they belong in. An OAuth client ID goes in `.env`; the resulting user-authorised access+refresh token goes in `integrations`.

After changing `.env` on a live server: `php artisan config:clear` and reload `php-fpm` (`deploy.sh` does this on every release).

---

## Social login (Socialite)

Four providers are wired: Google, GitHub, Microsoft, Apple. Routing is at `/auth/{provider}/redirect` → `/auth/{provider}/callback`, handled by `App\Http\Controllers\SocialLoginController`. Any provider left blank in `.env` disappears from the login page automatically.

### Google

1. [Google Cloud Console](https://console.cloud.google.com/) → **APIs & Services → Credentials**.
2. **Create credentials → OAuth client ID** → type **Web application**.
3. Authorised redirect URI: `https://bureau.homes/auth/google/callback`.
4. Copy **Client ID** + **Client secret**:

```env
GOOGLE_CLIENT_ID=…
GOOGLE_CLIENT_SECRET=…
# GOOGLE_REDIRECT_URL=…  # override if Bureau runs on a non-canonical URL
```

The same OAuth client powers the **Gmail inbound integration** below — one project, two uses. If you add Gmail later, extend its authorised scopes; you don't need a second client.

### GitHub

1. [GitHub → Settings → Developer settings → OAuth Apps → New OAuth App](https://github.com/settings/developers).
2. Homepage URL: `https://bureau.homes`. Authorization callback URL: `https://bureau.homes/auth/github/callback`.
3. **Generate a new client secret** — shown once, copy immediately.

```env
GITHUB_CLIENT_ID=…
GITHUB_CLIENT_SECRET=…
```

### Microsoft

1. [Azure Portal](https://portal.azure.com/) → **Microsoft Entra ID → App registrations → New registration**.
2. Supported accounts: **Accounts in any organizational directory and personal Microsoft accounts** (unless you want to restrict to a tenant).
3. Redirect URI: **Web** → `https://bureau.homes/auth/microsoft/callback`.
4. After creation: **Certificates & secrets → New client secret** — copy the *value* (not the Secret ID) immediately; it's shown once.
5. **Overview** → copy **Application (client) ID**.

```env
MICROSOFT_CLIENT_ID=…
MICROSOFT_CLIENT_SECRET=…
```

Socialite needs the community provider: `composer require socialiteproviders/microsoft`, then register via `SocialiteWasCalled` in `AppServiceProvider::boot()` (already in place — verify `app/Providers/AppServiceProvider.php`).

### Apple

Apple is different: the "client secret" is a short-lived JWT you sign yourself using a `.p8` key. Three pieces to collect and one script to run every ~6 months.

1. [Apple Developer → Certificates, Identifiers & Profiles](https://developer.apple.com/account/resources/identifiers/list).
2. **Identifiers → + → Services IDs**. Description: *Bureau*. Identifier: e.g. `homes.bureau.signin` (NOT `com.apple.…`). Save.
3. Re-open the Services ID → enable **Sign in with Apple** → **Configure** → Primary App ID: a registered App ID (create one if needed) → Domains and Return URLs: `bureau.homes` + `https://bureau.homes/auth/apple/callback`.
4. **Keys → + → Sign in with Apple** → download the resulting `.p8` (you only get to download once; save somewhere safe).
5. Note the **Key ID** (10-char) and your **Team ID** (top-right of the portal).

Compute the client secret JWT (expires every 6 months — automate this before shipping):

```bash
# Run once, put output into APPLE_CLIENT_SECRET. Regenerate before expiry.
php artisan tinker --execute='
    $key    = file_get_contents("/secure/path/AuthKey_XXXXXXXXXX.p8");
    $teamId = "YOUR_TEAM_ID";
    $kid    = "YOUR_KEY_ID";
    $sub    = "homes.bureau.signin";   // the Services ID
    echo \Illuminate\Support\Facades\Crypt::…;  // stub — use firebase/php-jwt
'
```

Use the `firebase/php-jwt` package (already in Laravel dep tree) to sign the JWT: `iss=$teamId`, `iat=now`, `exp=now+15777000` (≈6 months, Apple's max), `aud=https://appleid.apple.com`, `sub=$sub`, header `kid=$kid`, algo `ES256`.

```env
APPLE_CLIENT_ID=homes.bureau.signin
APPLE_CLIENT_SECRET=eyJhbG…        # the signed JWT
```

Socialite needs `composer require socialiteproviders/apple`.

---

## Gmail inbound (OAuth)

Reuses the Google OAuth client above. Scopes: `gmail.readonly` + `userinfo.email` (see `App\Http\Controllers\GmailOAuthController::SCOPES`). Per-user authorisation happens at runtime; tokens land in the `integrations` table encrypted.

1. Confirm `GOOGLE_CLIENT_ID` + `GOOGLE_CLIENT_SECRET` already set.
2. In the Google Cloud project → **APIs & Services → Library** → enable **Gmail API**.
3. **APIs & Services → OAuth consent screen** — while the project is unverified, add every user email under **Test users** (unverified apps can only serve test users).
4. User visits `/integrations/gmail/connect` → redirects to Google → consents → Bureau stores the refresh token.
5. To verify production use (removes the 100-test-user cap): submit the OAuth consent screen for Google verification. Expect ~2-6 weeks and a privacy-policy URL requirement.

---

## Fastmail JMAP (inbound)

App password, not OAuth — Fastmail supports long-lived app-scoped credentials.

1. Fastmail web → **Settings → Privacy & Security → API access (JMAP) → New API token**.
2. Scope: `Mail (read)`. Name: `bureau`. Copy the generated token.
3. Provision via the interactive command — it stores encrypted on the `integrations` row:

```bash
php artisan integrations:connect-fastmail
```

Prompts for the token, lists folders, saves the integration. No `.env` entry — everything lives encrypted in the DB.

---

## PayPal REST

Provisioned per-integration via the interactive command; credentials land encrypted on `integrations.credentials`.

1. [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/) → **Apps & Credentials** → pick **Live** (or **Sandbox** for testing).
2. **Create App** → type **Merchant**. Copy **Client ID** + **Secret**.
3. Under the app → **Webhooks → Add Webhook** → URL `https://bureau.homes/webhooks/paypal` → subscribe to the invoice/payment events Bureau consumes.
4. Connect:

```bash
php artisan integrations:connect-paypal
```

The only global `.env` toggle is webhook signature verification (always `true` in production):

```env
PAYPAL_VERIFY_WEBHOOK_SIGNATURE=true
```

Set it to `false` only in local dev where you don't have a registered webhook endpoint yet.

---

## Postmark

Two independent concerns.

### Inbound (webhook → `mail_messages`)

Postmark POSTs each received email to an endpoint on Bureau. Auth is HTTP Basic, embedded in the URL Postmark is configured with. `App\Http\Controllers\PostmarkInboundController` fails closed in production when credentials are blank (covered by `WebhookAuthTest`).

1. Postmark → **Servers → <your server> → Inbound settings**.
2. Generate a random `user:pass` — e.g. `openssl rand -hex 16` for each half.
3. Webhook URL: `https://user:pass@bureau.homes/webhooks/postmark/inbound`.

```env
POSTMARK_WEBHOOK_USER=…
POSTMARK_WEBHOOK_PASSWORD=…
```

### Outbound (sending)

1. Postmark → **Servers → <your server> → API Tokens** → copy a **Server API token**.
2. `.env`:

```env
MAIL_MAILER=postmark
POSTMARK_API_KEY=…
MAIL_FROM_ADDRESS=notifications@bureau.homes
MAIL_FROM_NAME=Bureau
```

**DNS records (SPF + DKIM + DMARC + Return-Path) are mandatory** — without them the first send bounces or spam-folders. Full walk-through with provider alternatives in [`docs/ops/outbound-email.md`](../ops/outbound-email.md).

---

## Backups (S3 / Backblaze B2)

`spatie/laravel-backup` pushes nightly archives to whichever filesystem disk `config/backup.php` lists under `destination.disks`. Currently `['local']` — for off-site storage add either S3 or B2.

### AWS S3

1. [AWS IAM → Users → Create user](https://console.aws.amazon.com/iam/home#/users) → programmatic access only, no console login.
2. Attach an inline policy scoped to the single bucket (no `s3:DeleteBucket`, no wildcards). Example:

```json
{
    "Statement": [{
        "Effect": "Allow",
        "Action": ["s3:PutObject", "s3:GetObject", "s3:ListBucket", "s3:DeleteObject"],
        "Resource": ["arn:aws:s3:::bureau-backups", "arn:aws:s3:::bureau-backups/*"]
    }]
}
```

3. Generate **access key**. Enable bucket-level **server-side encryption (SSE-S3 or SSE-KMS)** and **Object Lock** if you want immutable retention.

```env
AWS_ACCESS_KEY_ID=…
AWS_SECRET_ACCESS_KEY=…
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=bureau-backups
BACKUP_ARCHIVE_PASSWORD=…        # second encryption layer (AES-256 inside the .zip)
```

### Backblaze B2 (cheaper alternative)

B2 speaks the S3 API in "Application Keys" mode.

1. [Backblaze → My Account → Application Keys → Add a New Application Key](https://secure.backblaze.com/app_keys.htm). Scope to one bucket. Permissions: `readFiles, writeFiles, listFiles, deleteFiles`.
2. Note the **keyID**, **applicationKey**, and the **endpoint** (`s3.us-west-004.backblazeb2.com` or similar).

```env
AWS_ACCESS_KEY_ID=<keyID>
AWS_SECRET_ACCESS_KEY=<applicationKey>
AWS_DEFAULT_REGION=us-west-004
AWS_BUCKET=bureau-backups
AWS_ENDPOINT=https://s3.us-west-004.backblazeb2.com
AWS_USE_PATH_STYLE_ENDPOINT=true
BACKUP_ARCHIVE_PASSWORD=…
```

Then flip `config/backup.php`'s `destination.disks` to `['s3']` (or `['local', 's3']` for belt-and-braces). Always set `BACKUP_ARCHIVE_PASSWORD` — the archive travels over the internet even when TLS is in effect; second-layer symmetric encryption means a stolen bucket credential alone can't read the backup.

---

## LM Studio (local LLM, Tier 2 OCR)

Not an "integration" in the credential sense — bound to the LAN. Doesn't leave your machine. No key required beyond reaching the LM Studio HTTP server.

```env
LM_STUDIO_ENABLED=true
LM_STUDIO_BASE_URL=http://<host-ip>:1234/v1
LM_STUDIO_MODEL=qwen2.5-coder-7b-instruct
LM_STUDIO_TIMEOUT=120
```

WSL2 gotcha: point `BASE_URL` at the Windows host IP (from `ip route show default`), not `localhost`, and enable *Serve on Local Network* in LM Studio so the listener binds `0.0.0.0`. See the comment in `.env.example`.

---

## Deferred / not yet wired

Listed for planning. No code path or env var exists yet.

- **Plaid (US banking)** — `PLAID_CLIENT_ID`, `PLAID_SECRET`, `PLAID_ENV=sandbox|development|production`. Items (per-institution link tokens) stored per-integration row. Webhooks for transactions + item errors.
- **GoCardless Bank Account Data (EU)** — OAuth2, institution picker UI, per-bank consent valid 90/180d.
- **Twilio (SMS)** — `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM`. Per-reminder cost; use for high-urgency attention radar events only.
- **Slack (notifications)** — incoming-webhook URL per household, stored in `integrations`. No OAuth flow needed if you're only posting.
- **Telegram bot** — bot token from `@BotFather`, per-user chat ID from `/start`. Cheapest "mobile push" channel if you don't want SMS.
- **Apple Push / Web Push** — VAPID keypair for Web Push (deferred until PWA maturity warrants it).

---

## Rotation & hygiene

- **Never commit `.env`.** The deploy script's security gate refuses to proceed if `.env` is tracked by git.
- **After rotating a secret** → `php artisan config:clear` → reload php-fpm.
- **Apple client secret** expires after 6 months. Set a calendar reminder or automate regeneration in a scheduled job.
- **OAuth refresh tokens** can be invalidated by the provider at any time (user revoke, password change, TOS accept). Bureau should surface "integration needs reconnection" on the attention radar when a refresh fails — tracked as future work.
- **Per-environment separation**: never reuse a production OAuth client ID in dev. A mis-redirected auth code in dev can leak a refresh token to `localhost:8000`.
- **Audit log**: mutations against `integrations` rows should land in the forthcoming `audits` table (see ROADMAP.md data-stewardship section).

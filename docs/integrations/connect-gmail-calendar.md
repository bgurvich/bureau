# Connect Gmail (and Google Calendar)

End-to-end how-to for wiring a Google account into Secretaire so
inbound mail gets ingested automatically. Calendar sync shares the
same OAuth client and is sketched at the bottom — the scaffolding is
in place but the sync itself isn't shipped yet.

> **Related docs**
> - [auth-credentials.md](auth-credentials.md) — provider reference for every integration, not just Google.
> - [outbound-email.md](../ops/outbound-email.md) — the *send* side (Postmark). This doc is read-only inbound.

## What "connected" means

A connected Gmail integration:
- Ingests messages matching the labels you pick (default: none → nothing is pulled) into `mail_messages`.
- Pulls attachments on demand and routes receipts/bills through the OCR pipeline into the mobile **Inbox**.
- Runs on a 10-minute cron (`mail:sync`), incremental from Gmail's `historyId` cursor so each run is cheap.
- Refreshes the access token automatically. If Google ever refuses the refresh (you revoked the app, changed your password, or TOS'd out), Secretaire flips the integration to `status=error`, surfaces it on the Attention radar, and renders a **Reconnect** button on `/profile`.

## Prerequisites

- A Google account (the one whose mailbox you want to ingest).
- Access to [Google Cloud Console](https://console.cloud.google.com/) with permission to create OAuth clients.
- `APP_URL` in Secretaire's `.env` reachable by Google's consent-screen redirect. HTTPS is required in production; `http://localhost:8000` is accepted in dev.
- Shell access to run `php artisan` on the Secretaire instance.

## 1. Create or reuse a Google Cloud OAuth client

Secretaire uses **one** OAuth client for social sign-in, Gmail, and (eventually) Calendar. If you already set up Google social login per `auth-credentials.md`, skip to step 2.

1. Cloud Console → select or create a project dedicated to Secretaire.
2. **APIs & Services → OAuth consent screen**
   - User type: **External** (Google Workspace tenants can pick *Internal* and skip the verification dance below).
   - App name: `Secretaire` · support email: yours · developer contact: yours.
   - Save — you'll hit scopes on the next page but those are added per-API below.
3. **APIs & Services → Credentials → Create credentials → OAuth client ID**
   - Application type: **Web application**.
   - Authorized redirect URI (**exactly** matching):
     ```
     https://secretaire.aurnata.com/integrations/gmail/callback
     ```
     plus a dev one if you develop locally:
     ```
     http://localhost:8000/integrations/gmail/callback
     ```
4. Copy **Client ID** and **Client secret** into `.env`:
   ```env
   GOOGLE_CLIENT_ID=…
   GOOGLE_CLIENT_SECRET=…
   ```
5. `php artisan config:clear` (on the server) so the new env lands in the cached config.

## 2. Enable the Gmail API

1. Cloud Console → **APIs & Services → Library**.
2. Search "Gmail API" → **Enable**.
3. Back on **OAuth consent screen**:
   - **Scopes** → add `.../auth/gmail.readonly` and `.../auth/userinfo.email`. Both are "sensitive" scopes and need a justification line in the verification form eventually; while you're in testing, you can proceed without submitting.
   - **Test users** → add the Gmail address you want to connect (and every other one you'll connect in the future). Unverified apps can only grant access to listed test users — up to 100.

## 3. Connect your Gmail account

1. Sign in to Secretaire as the owner.
2. Navigate to `/profile` → **Personal integrations** → **Connect Gmail**.
3. Google's consent screen opens. Accept the scopes.
4. Google redirects you back to Secretaire with a code. Secretaire exchanges it for tokens and stores them encrypted.

On success you see a flash: *"Gmail connected: you@example.com"* and a new row in the Personal integrations list showing provider=gmail, kind=mail, status=active.

### If you get "Google did not return a refresh_token"

Google only issues a refresh token on the **first** consent for a given (user, client) pair. If you previously authorized Secretaire and revoked it somewhere else, the next consent will return only an access token.

Fix: open [myaccount.google.com/permissions](https://myaccount.google.com/permissions), remove Secretaire, then retry `/integrations/gmail/connect`.

## 4. Pick which labels Secretaire watches

By default the integration watches **nothing** — you opt in to specific labels so Secretaire only touches the mail you care about (receipts, bills, statements).

```bash
php artisan integrations:gmail-labels
```

The command:
- Lists all labels on the connected mailbox.
- Lets you multi-select via arrow keys.
- Writes the chosen label IDs into `integrations.settings.label_ids`.

You can rerun it any time to change the selection.

### Label suggestion

Gmail filters work well for this. In Gmail:
1. Create a label, e.g. `Secretaire/Receipts`.
2. **Settings → Filters and blocked addresses → Create new filter** → set criteria (from:amazon.com, subject:receipt, etc.) → **Apply label: Secretaire/Receipts**.
3. `integrations:gmail-labels` will show the new label on the next run — select it.

This keeps Secretaire's view of your mail explicitly scoped instead of slurping the whole inbox.

## 5. Verify sync

```bash
# Dry-run — shows what would be ingested without persisting:
php artisan mail:sync --dry-run

# Real run for your household:
php artisan mail:sync --household=1
```

After a successful run, `integrations.last_synced_at` updates on the grid. The cron (`mail:sync`, every 10 minutes) takes over from there; check `schedule:list` in `php artisan` to confirm the task is registered.

Ingested mail lands in the `mail_messages` table. Receipts + bills flow into the mobile **Inbox** for you to file.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Redirect hits `400 Invalid OAuth state` | Session cookie dropped between `/connect` and `/callback`. | Confirm `SESSION_DOMAIN` in `.env` matches your APP_URL domain (or leave unset in dev). |
| `500 Google token exchange failed` | Redirect URI mismatch, or client_secret typo. | Compare the URL Google redirected to with the exact value registered in the Cloud Console. Secrets get truncated on paste — re-copy. |
| Integration flipped to `status=error` with "Gmail refresh token rejected" | You (or Google) revoked the app, or the password/2FA changed. | Click **Reconnect** on `/profile` — it re-runs the OAuth flow and issues a new refresh token. |
| `mail:sync` runs but nothing ingests | No labels selected, or filters don't match. | Rerun `integrations:gmail-labels` and confirm at least one label has messages in it. |
| Sync works in dev but not prod | `.env` cached stale. | `php artisan config:clear && php artisan config:cache` on the server. |

### Forcing a full re-backfill

```bash
# In tinker:
$i = \App\Models\Integration::where('provider', 'gmail')->first();
$s = $i->settings;
unset($s['history_id']);       // clears the cursor → next run backfills
$i->settings = $s;
$i->save();
```

Then `php artisan mail:sync --integration={id}`.

## Production readiness

Secretaire runs single-user, so Google's OAuth verification process is optional — you stay in "Testing" mode indefinitely with your own email on the test-users list.

If you plan to onboard additional users (multi-household), submit the OAuth consent screen for verification:
- Requires a linked privacy policy URL served on the app domain.
- 2–6 weeks for approval; can request "restricted scope" verification if you need gmail.modify or similar higher-trust scopes later.

Until then, every person who connects must be on the Test users list in the consent screen configuration.

---

## Calendar (not shipped)

The model scaffolding exists (`App\Models\CalendarFeed`, `Meeting::calendarFeed()` relation, `Integration` supports `kind=calendar`), but there is no Google-Calendar-sync code, no OAuth scope for Calendar in `GmailOAuthController::SCOPES`, and no scheduled command yet. The settings page calls this out: *"Calendar sync is on the roadmap; no connector ships yet."*

When it does ship, the connection flow will mirror Gmail's:

1. Reuse the same Google Cloud project. Enable **Google Calendar API** in the Library.
2. Add `.../auth/calendar.readonly` to the OAuth consent screen scopes.
3. `GmailOAuthController::SCOPES` will grow to include calendar.readonly, or a sibling `CalendarOAuthController` will handle a separate flow so the user can opt into mail + calendar independently.
4. A new `GoogleCalendarProvider` parallel to `GmailProvider` will pull events off `calendars/primary/events?syncToken=…` and materialize them as `Meeting` rows tied to a `CalendarFeed`.
5. `mail:sync` extends (or a `calendar:sync` sibling ships) to run the calendar pull on the same cadence.

### Today's workaround (ICS subscription)

Until the native connector lands, the only way to see Google Calendar events in Secretaire is to export the ICS feed and ingest it via the existing Meeting model manually, or point another tool (iCal, Thunderbird) at the feed. This is out of scope for Secretaire today.

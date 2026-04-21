# Outbound email — DNS + provider setup

Bureau sends reminders, weekly digests, and magic-link auto-login URLs via `Mail::to(...)->send(...)`. For any of that to actually land in Gmail / iCloud / Fastmail inboxes (vs bounce or spam-folder), the sender domain needs SPF + DKIM + DMARC + a Return-Path, and you need a transactional provider to do the SMTP (or HTTP API) work.

**Don't self-host outbound SMTP.** A fresh VPS IP has zero sender reputation, is probably on consumer-block lists, and even with perfect DKIM alignment major providers will bin your mail. Use a transactional provider — free tiers cover personal use.

## Provider picks

| Provider | Free tier | Fit for Bureau |
|---|---|---|
| **Postmark** | 100/mo free, $15/mo at 10k | Best — Bureau already has Postmark inbound wiring; outbound shares domain verification. Native Laravel driver (no SMTP). |
| **Amazon SES** | 62k/mo (from EC2) | Cheap at volume but sandbox exit requires a support ticket. |
| **Resend** | 3k/mo, 100/day | Easiest onboarding; clean dashboard. |
| **Mailgun** | 100/day free during trial | Solid deliverability; good docs. |

The rest of this guide uses **Postmark** as the worked example. Every other provider is the same shape — swap host/creds, DNS records end up under different selector names but the four-record structure is identical.

---

## 1. Postmark-side setup

1. [Postmark → Sending → Servers](https://account.postmarkapp.com/servers) → **Create Server** (name it `bureau-prod`).
2. **Sending → Domains → Add Domain** → enter `bureau.homes`.
3. Postmark shows four DNS records to add. Keep the tab open — you'll paste from it verbatim.

## 2. DNS records

Four records on `bureau.homes`'s authoritative DNS (Cloudflare, Route 53, your registrar's built-in, etc.). **Paste from Postmark's verification panel — the selector names and target hosts differ per Postmark server.**

| Type | Host | Value | What it proves |
|---|---|---|---|
| **SPF** (TXT) | `@` (apex) | `v=spf1 include:spf.mtasv.net ~all` | Postmark's MTAs are authorised to send for this domain. |
| **DKIM** (TXT) | `20240101pm._domainkey` (example selector) | `k=rsa; p=MIGfMA0GCSqGSIb3…` (Postmark-provided) | Cryptographic signature proves messages were actually sent by Postmark on your behalf, not a spoofer. |
| **Return-Path** (CNAME) | `pm-bounces` | `pm.mtasv.net` | Bounces route back through Postmark. Aligns `MAIL FROM` with `From:` so DMARC passes. |
| **DMARC** (TXT) | `_dmarc` | `v=DMARC1; p=none; rua=mailto:dmarc@bureau.homes` | Reporting policy. Start with `p=none`, tighten to `p=quarantine` → `p=reject` after a week of clean reports. |

**Plus reverse DNS (PTR)** on the VPS — set via your hosting provider's control panel (Hetzner, DO, Linode, AWS) so `bureau.homes` resolves to the box's IP AND the box's IP resolves back to `bureau.homes`. For transactional-provider sending this is cosmetic (Postmark's MTAs send, not yours), but it's mandatory if you ever flip to self-hosted SMTP.

Wait ~15 min for propagation, then in Postmark click **Verify DNS**. Four green checks.

### Postmark UI quirk — the server name leaks into record labels

Postmark appends the *server* name (e.g. `boris`) to every record it shows. If your server is called `boris` and your domain is `bureau.homes`, Postmark displays:

```
20260421071852pm._domainkey.boris
pm-bounces.boris
```

Strip the `.boris` before pasting into DNS. The real host is `20260421071852pm._domainkey` and `pm-bounces` respectively; your DNS zone auto-appends `.bureau.homes`. Postmark's **Verify** step queries the domain-scoped hostname (not the server-scoped one), so the records match after you strip.

If the server is named the same as a subdomain you actually want to send from (e.g. `boris.bureau.homes`), then keep the suffix — but it has to be consistent: SPF on the subdomain apex, `MAIL_FROM_ADDRESS=...@boris.bureau.homes`, etc.

### Record syntax notes

- **SPF**: one `v=spf1` record per domain, max 10 DNS lookups. `~all` = soft-fail (recommended during rollout). Tighten to `-all` after a week.
- **DKIM selector**: Postmark rotates keys annually; the selector (`20240101pm`) changes on key rotation. Keep both old + new selectors in DNS during rotation — Postmark's UI flags when old can go.
- **DMARC `rua`**: aggregate reports. Create a throwaway inbox for the address; you'll get daily XML reports that read like firehose logs. `ruf` (per-message forensics) is usually too noisy to enable.
- **DMARC alignment**: `From:` domain must match SPF's Return-Path domain OR DKIM's signing domain. With Postmark's Return-Path CNAME above, both align automatically.

---

## 3. Bureau `.env`

Laravel has a native Postmark driver — no SMTP plumbing needed. Use `install.sh --only env` or edit `.env` directly:

```
MAIL_MAILER=postmark
POSTMARK_API_KEY=<postmark-server-token>
MAIL_FROM_ADDRESS="notifications@bureau.homes"
MAIL_FROM_NAME="${APP_NAME}"
```

The server token lives in the Postmark server you created → **API Tokens → Server API Token**. Copy-paste — it's treated as a secret.

If you need generic SMTP instead (for a Postmark alternative or self-hosted MTA):

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.postmarkapp.com
MAIL_PORT=587
MAIL_USERNAME=<postmark-server-token>    # Postmark uses the token as user + pass
MAIL_PASSWORD=<postmark-server-token>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="notifications@bureau.homes"
MAIL_FROM_NAME="${APP_NAME}"
```

Then:

```bash
php artisan config:clear
sudo systemctl reload php8.3-fpm
```

(Or just re-run `bash scripts/deploy/install.sh --only env artisan` and it'll pick up the new values.)

---

## 4. Test

```bash
php artisan tinker --execute='\Mail::raw("Bureau outbound ping", fn($m) => $m->to("you@example.com")->subject("Bureau test"));'
```

Check your inbox (and the Postmark dashboard's **Activity** tab — you'll see the message + the DKIM/SPF/DMARC verdict Gmail or Yahoo returned).

Gmail shows a little lock icon + "mailed by bureau.homes" when all three align.

---

## Other providers, same shape

Each provider gives you its own SPF include + DKIM selector + bounce domain. Drop-in replacements for `.env`:

### Amazon SES

```
MAIL_MAILER=ses
MAIL_FROM_ADDRESS="notifications@bureau.homes"
AWS_ACCESS_KEY_ID=AKIA...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-1
```

DNS: `v=spf1 include:amazonses.com ~all` + DKIM TXT records (three of them) that SES's domain-verification page generates + same DMARC.

### Resend

```
MAIL_MAILER=resend
RESEND_API_KEY=re_...
MAIL_FROM_ADDRESS="notifications@bureau.homes"
```

(Laravel 11.x has native `resend` driver via `resend/resend-laravel`.) DNS: Resend gives you a dashboard of the exact TXT records to paste.

### Mailgun

```
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=mg.bureau.homes
MAILGUN_SECRET=key-...
MAILGUN_ENDPOINT=api.mailgun.net
MAIL_FROM_ADDRESS="notifications@bureau.homes"
```

DNS: typically runs on a subdomain (`mg.bureau.homes`) to isolate reputation from your main domain. SPF + DKIM + CNAME records per Mailgun's domain-verification page.

### Generic SMTP

Any provider that gives you host + port + user/pass — drop into the `smtp` shape above. Common ports: 587 (STARTTLS), 465 (SMTPS), 2525 (fallback when 587 is blocked by the hosting provider).

---

## Gotchas

- **From-address alignment**: `MAIL_FROM_ADDRESS` domain must match the verified Postmark/SES/etc. domain. Sending `From: random@gmail.com` through Postmark breaks DMARC and lands in spam.
- **Reminder + weekly-digest mail** both use `MAIL_FROM_ADDRESS` by default, and magic-link auto-login CTAs inherit it — one verified sender covers every outbound path.
- **DMARC reports** (`rua=mailto:...`): create a throwaway inbox. You'll get daily XML reports showing every message sent on your behalf — catches abuse + misconfigs.
- **SPF lookup cap**: don't chain too many `include:` — hard cap of 10 DNS lookups per RFC 7208. One provider `include:` is fine.
- **Subdomain strategy** for the paranoid: send from `mail.bureau.homes` (separate subdomain) so reputation damage from a spam blast (or a vendor compromise) stays off the apex domain.
- **Rate limits**: Postmark throttles to 100 messages per batch; Bureau's `SendWeeklyDigest` is a single-user command so it's not near the limit, but queue a `->delay()` if you ever graduate to multi-tenant.
- **Soft vs hard bounces**: Postmark auto-suppresses hard bounces after one. If a user complains they're not getting mail, check **Activity → Suppressions** before assuming DNS.

## References

- Postmark DKIM rotation: <https://postmarkapp.com/support/article/1075-how-to-rotate-your-domain-s-dkim-key>
- DMARC reporting (read the XML reports): <https://dmarcian.com/>
- RFC 7208 (SPF), RFC 6376 (DKIM), RFC 7489 (DMARC).

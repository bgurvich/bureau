# Outbound email — DNS + provider setup

Secretaire sends reminders, weekly digests, and magic-link auto-login URLs via `Mail::to(...)->send(...)`. For any of that to actually land in Gmail / iCloud / Fastmail inboxes (vs bounce or spam-folder), the sender domain needs SPF + DKIM + DMARC + a Return-Path, and you need a transactional provider to do the SMTP (or HTTP API) work.

**Don't self-host outbound SMTP.** A fresh VPS IP has zero sender reputation, is probably on consumer-block lists, and even with perfect DKIM alignment major providers will bin your mail. Use a transactional provider — free tiers cover personal use.

## Provider picks

| Provider | Free tier | Fit for Secretaire |
|---|---|---|
| **Postmark** | 100/mo free, $15/mo at 10k | Best — Secretaire already has Postmark inbound wiring; outbound shares domain verification. Native Laravel driver (no SMTP). |
| **Amazon SES** | 62k/mo (from EC2) | Cheap at volume but sandbox exit requires a support ticket. |
| **Resend** | 3k/mo, 100/day | Easiest onboarding; clean dashboard. |
| **Mailgun** | 100/day free during trial | Solid deliverability; good docs. |

The rest of this guide uses **Postmark** as the worked example. Every other provider is the same shape — swap host/creds, DNS records end up under different selector names but the four-record structure is identical.

---

## 1. Postmark-side setup

Postmark separates two concepts — and the distinction trips people up on first setup:

- **Server** = a message bucket + API-token scope. You can call it anything. The name you pick becomes part of every DNS record Postmark renders in its UI (see the "server-name leaks" quirk below).
- **Sending Domain** = the actual `@secretaire.aurnata.com` sender identity. DKIM, Return-Path, DMARC all hang off this. Added at the **account level**, not inside a server.

Steps:

1. [Postmark → Servers](https://account.postmarkapp.com/servers) → **Create Server**. Name it whatever you like (`secretaire-prod`, `live`, etc.). This is just a label.
2. Inside that server, **API Tokens → Server API Token** — copy it. This is what goes into `.env` as `POSTMARK_API_KEY`.
3. **Top nav → Sender Signatures** (or "Sending Domains" — Postmark has renamed this a couple times) → **Add Domain** → enter `secretaire.aurnata.com`. **This is the step people miss.** Without a verified domain, the DKIM selectors Postmark offers are server-scoped stubs, not domain records.
4. Postmark shows three DNS records to add. Keep the tab open — you'll paste from it verbatim.

## 2. DNS records

Three records on `secretaire.aurnata.com`'s authoritative DNS (Name.com, Cloudflare, Route 53, your registrar's built-in, etc.). **Paste from Postmark's verification panel — the selector name is timestamp-based (unique per Postmark server) and the DKIM public key is unique per domain.**

| Type | Host | Value | What it proves |
|---|---|---|---|
| **DKIM** (TXT) | `20260421pm._domainkey` (example — Postmark's exact selector differs) | `k=rsa; p=MIGfMA0GCSqGSIb3…` (Postmark-provided) | Cryptographic signature proves messages were actually sent by Postmark on your behalf, not a spoofer. |
| **Return-Path** (CNAME) | `pm-bounces` | `pm.mtasv.net` | Bounces route back through Postmark. DMARC alignment-via-SPF is inherited from `pm.mtasv.net`'s own SPF record, which is why you no longer need an apex `v=spf1 include:spf.mtasv.net` record. |
| **DMARC** (TXT) | `_dmarc` | `v=DMARC1; p=none; rua=mailto:dmarc@secretaire.aurnata.com; fo=1` | Reporting policy. Start with `p=none`, tighten to `p=quarantine` → `p=reject` after a week of clean reports. |

> **Postmark dropped the SPF requirement** (as of late 2025 — their UI reads "We no longer require SPF DNS records since it's automatically handled for you"). DMARC alignment happens via DKIM instead of SPF on the apex. If you publish multiple senders (e.g. Mailgun AND Postmark), you still want your own `v=spf1 include:... -all` on the apex — but with a single provider, skip it.

**Plus reverse DNS (PTR)** on the VPS — set via your hosting provider's control panel (Hetzner, DO, Linode, AWS) so `secretaire.aurnata.com` resolves to the box's IP AND the box's IP resolves back to `secretaire.aurnata.com`. For transactional-provider sending this is cosmetic (Postmark's MTAs send, not yours), but it's mandatory if you ever flip to self-hosted SMTP.

Wait ~5-15 min for propagation, then in Postmark click **Verify**. All three records green.

### Postmark UI quirk — the server name leaks into record labels

Postmark appends the *server* name (e.g. `boris`) to every record it shows in the verification panel. If your server is called `boris` and your domain is `secretaire.aurnata.com`, Postmark displays:

```
20260421071852pm._domainkey.boris
pm-bounces.boris
```

**Strip the `.boris` suffix before pasting into DNS.** The real host is `20260421071852pm._domainkey` and `pm-bounces` respectively; your DNS zone auto-appends `.secretaire.aurnata.com` so the records resolve at the domain apex. Postmark's **Verify** step queries the domain-scoped FQDN (not the server-scoped one), so the records match after you strip.

If the server name happens to match an actual subdomain you want to send from (e.g. `boris.secretaire.aurnata.com`), keep the suffix — but it has to be consistent: SPF on the subdomain apex, `MAIL_FROM_ADDRESS=...@boris.secretaire.aurnata.com`, etc.

### DNS host-field gotchas

- Registrars auto-append the zone. Type only the leaf label: `20260421pm._domainkey` — **not** `20260421pm._domainkey.secretaire.aurnata.com`. If you paste the full FQDN, the record ends up at `20260421pm._domainkey.secretaire.aurnata.com.secretaire.aurnata.com` (doubled zone) and Postmark can't find it.
- Name.com in particular locks the zone to the right of the Host field — you literally can only edit the subdomain part, which prevents the doubled-zone mistake. Leave it blank for apex (`@`) records, type the subdomain label otherwise.
- After you click Save in Name.com, refresh the page and confirm the record shows up in the zone listing. If it doesn't appear, the save didn't persist (usually a validation issue that swallowed silently — check the value field for unescaped special characters).
- DKIM values can exceed 255 characters. Most DNS UIs accept a single long string and chunk it automatically; some older ones need you to paste as `"chunk1" "chunk2"` (multi-string TXT). If dig returns multiple quoted chunks, that's fine — RFC 6376 allows it.
- `_dmarc` is literal, with the underscore. Some UIs reject underscores in "Host" fields — if yours does, paste `_dmarc` anyway; most modern registrars accept it.

### Record syntax notes

- **DKIM selector**: timestamped per your Postmark domain-verification event (e.g. `20260421075019pm` = 2026-04-21 07:50:19). Postmark also rotates annually. Any time you delete + re-add the domain in Postmark, the selector changes — so does the key — and the old DNS record becomes dead weight. Update DNS to match the current selector; delete stale selectors once Postmark shows verified.
- **DMARC `rua`**: aggregate reports. Create a throwaway inbox for the address; you'll get daily XML reports that read like firehose logs. `ruf` (per-message forensics) is usually too noisy to enable.
- **DMARC alignment**: `From:` domain must match SPF's Return-Path domain OR DKIM's signing domain. With Postmark's Return-Path CNAME + DKIM above, alignment passes automatically.

### Diagnostic: did my record actually land?

Before clicking **Verify** in Postmark, confirm with `dig` — saves a round-trip:

```bash
# DKIM (replace the selector with whatever Postmark generated)
dig TXT 20260421pm._domainkey.secretaire.aurnata.com +short

# Return-Path
dig CNAME pm-bounces.secretaire.aurnata.com +short
#   → expects: pm.mtasv.net.

# DMARC
dig TXT _dmarc.secretaire.aurnata.com +short
#   → expects: "v=DMARC1; p=none; rua=mailto:..."

# Bypass recursive caches — query the zone's authoritative NS directly
dig TXT 20260421pm._domainkey.secretaire.aurnata.com @ns1.name.com +short
```

Empty output at the authoritative NS = the record isn't in the zone yet. Empty only at public resolvers = propagation delay; retry in 5 min.

---

## 3. Secretaire `.env`

Laravel has a native Postmark driver — no SMTP plumbing needed. Use `install.sh --only env` or edit `.env` directly:

```
MAIL_MAILER=postmark
POSTMARK_API_KEY=<postmark-server-token>
MAIL_FROM_ADDRESS="notifications@secretaire.aurnata.com"
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
MAIL_FROM_ADDRESS="notifications@secretaire.aurnata.com"
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
php artisan tinker --execute='\Mail::raw("Secretaire outbound ping", fn($m) => $m->to("you@example.com")->subject("Secretaire test"));'
```

Check your inbox (and the Postmark dashboard's **Activity** tab — you'll see the message + the DKIM/SPF/DMARC verdict Gmail or Yahoo returned).

Gmail shows a little lock icon + "mailed by secretaire.aurnata.com" when all three align.

---

## Other providers, same shape

Each provider gives you its own SPF include + DKIM selector + bounce domain. Drop-in replacements for `.env`:

### Amazon SES

```
MAIL_MAILER=ses
MAIL_FROM_ADDRESS="notifications@secretaire.aurnata.com"
AWS_ACCESS_KEY_ID=AKIA...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-1
```

DNS: `v=spf1 include:amazonses.com ~all` + DKIM TXT records (three of them) that SES's domain-verification page generates + same DMARC.

### Resend

```
MAIL_MAILER=resend
RESEND_API_KEY=re_...
MAIL_FROM_ADDRESS="notifications@secretaire.aurnata.com"
```

(Laravel 11.x has native `resend` driver via `resend/resend-laravel`.) DNS: Resend gives you a dashboard of the exact TXT records to paste.

### Mailgun

```
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=mg.secretaire.aurnata.com
MAILGUN_SECRET=key-...
MAILGUN_ENDPOINT=api.mailgun.net
MAIL_FROM_ADDRESS="notifications@secretaire.aurnata.com"
```

DNS: typically runs on a subdomain (`mg.secretaire.aurnata.com`) to isolate reputation from your main domain. SPF + DKIM + CNAME records per Mailgun's domain-verification page.

### Generic SMTP

Any provider that gives you host + port + user/pass — drop into the `smtp` shape above. Common ports: 587 (STARTTLS), 465 (SMTPS), 2525 (fallback when 587 is blocked by the hosting provider).

---

## Gotchas

- **From-address alignment**: `MAIL_FROM_ADDRESS` domain must match the verified Postmark/SES/etc. domain. Sending `From: random@gmail.com` through Postmark breaks DMARC and lands in spam.
- **Reminder + weekly-digest mail** both use `MAIL_FROM_ADDRESS` by default, and magic-link auto-login CTAs inherit it — one verified sender covers every outbound path.
- **DMARC reports** (`rua=mailto:...`): create a throwaway inbox. You'll get daily XML reports showing every message sent on your behalf — catches abuse + misconfigs.
- **SPF lookup cap**: don't chain too many `include:` — hard cap of 10 DNS lookups per RFC 7208. One provider `include:` is fine.
- **Subdomain strategy** for the paranoid: send from `mail.secretaire.aurnata.com` (separate subdomain) so reputation damage from a spam blast (or a vendor compromise) stays off the apex domain.
- **Rate limits**: Postmark throttles to 100 messages per batch; Secretaire's `SendWeeklyDigest` is a single-user command so it's not near the limit, but queue a `->delay()` if you ever graduate to multi-tenant.
- **Soft vs hard bounces**: Postmark auto-suppresses hard bounces after one. If a user complains they're not getting mail, check **Activity → Suppressions** before assuming DNS.

## References

- Postmark DKIM rotation: <https://postmarkapp.com/support/article/1075-how-to-rotate-your-domain-s-dkim-key>
- DMARC reporting (read the XML reports): <https://dmarcian.com/>
- RFC 7208 (SPF), RFC 6376 (DKIM), RFC 7489 (DMARC).

# Bureau — features & roadmap

Two lists: (1) everything already implemented, (2) what's queued. Keep both
honest: delete shipped items from the roadmap as they land; prune roadmap
items when you decide not to do them. This file is the single source of
truth.

---

## Implemented

### Money

**Accounts & balances**
- Account types: checking, savings, credit, investment, gift card / prepaid, loan
- Opening balance + `AccountBalance` snapshots over time (`latestBalance`)
- `include_in_net_worth` toggle per account
- Net-worth computation across active accounts + asset valuations
- Monthly net-worth + cashflow snapshots (rollup cron) feeding sparklines
- Gift-card / prepaid expiration detection

**Transactions**
- Ledger model with `amount`, `occurred_on`, `currency`, `status`, `external_id`, `import_source`
- Counterparty (Contact) linkage, category assignment
- Reference number, description, notes
- `reconciled` state vs `pending`
- Media attachments (receipts) via polymorphic pivot with role
- Tags (auto and manual)
- Search + filter by account / status / counterparty / date range / category / tag / free text
- Sortable columns

**Transfers**
- Cross-account transfers recorded as atomic pairs
- Bank-to-bank `TransferPairing` (auto-detect offsetting entries)

**Recurring rules + projections**
- `RecurringRule` with RRULE (monthly / weekly / biweekly / quarterly / yearly / daily + INTERVAL)
- Human-readable rrule labels (`RruleHumanize`)
- Projections materialized N days ahead (`recurring:project` daily cron)
- `ProjectionMatcher` exact + fuzzy (≤10% amount, ±tolerance days)
- `RecurringPatternDiscovery` (detects unmodeled recurrences, ≥3 occurrences + 90d span)
- Discovery UI: pending list, one-click accept-as-subscription, review-in-inspector path, bulk dismiss

**Subscriptions (first-class)**
- `Subscription` table linked 1:1 to `RecurringRule`, nullable link to `Contract` (cancellation side)
- Auto-created on new outflow rule via observer
- Contract auto-linked by counterparty when created
- `/subscriptions` page: list, filter (state), sort (name/amount/monthly), "Show cancelled" toggle
- Pause / Resume / Cancel / Reactivate quick actions (no Inspector round-trip)
- Paused-until date + daily `subscriptions:resume-due` cron
- Monthly + annual totals (excludes paused)
- Backfill command for historical rules

**Budgets**
- Per-category monthly caps (`BudgetCap`)
- `BudgetMonitor` computes MTD utilization → ok / warning (≥80%) / over (≥100%)
- `/budgets` page: CRUD via Inspector, filter (state), sort (category/cap/spent/utilization)
- `BudgetAutoSuggester` with P75-of-6-months and one-click apply

**Spending insights**
- Year-over-year comparison (monthly + per-category breakdown)
- Bar-chart visual using theme tokens
- Category row links to filtered transactions view
- Spending anomaly detection (>2.5σ above 90-day category baseline, requires 5+ samples)

**Categorization**
- Category tree (parent + children), kind = expense / income / transfer
- `StarterCategoriesSeeder` seeds 50+ common categories on fresh household
- `SystemCategoriesSeeder` for engine-required slugs (interest-paid/interest-earned)
- `CategoryRule` + observer: auto-assign on transaction create; regex + contains patterns; priority-ordered
- `TagRule` + observer: auto-attach tags additively
- `categories:apply` command re-categorizes historical uncategorized transactions
- Searchable + addable category dropdowns in Inspector (transaction / bill / budget / rule pickers)
- Unique constraint `(household_id, slug)` prevents duplicate categories

**Statements import**
- Multi-file upload + ZIP unpacking
- 8 PDF parsers + 7 CSV parsers (Wells Fargo checking/credit, Citi checking/credit, Amex checking/credit, OnPoint checking/credit, PayPal)
- File-level dedup via `media.hash`
- Row-level dedup via `external_id = sha1(account|date|description|amount)` + ±3d fuzzy match
- Counterparty auto-resolve / auto-create from description
- Base classes + shared helpers (dedup across parsers)

**Receipts**
- OCR pipeline (tesseract on image/PDF, poppler pdftotext for PDFs)
- OCR Tier 2 structured extraction via LM Studio (vendor, amount, date, line items) — works but slow on current GPU
- `ReceiptMatcher` service + `receipts:match` cron: auto-pair receipts to outflow transactions (±3d, exact amount)
- Receipt thumbnails on transaction rows

**Bills inbox**
- `/inbox` showing unprocessed media
- Bulk create-transactions action from inspector prefill

**Savings goals**
- `SavingsGoal` with target amount, target date, starting + saved amounts
- Optional linked Account (progress auto-computed from balance)
- State: active / paused / achieved / abandoned
- Milestone tracking (25/50/75/100%) — fires reminders once per crossing
- `/savings-goals` page with progress bars, filter, sort

**PayPal**
- Client-credentials OAuth + Reporting API sync (`paypal:sync` hourly)
- Webhook endpoint with local signature verification
- Bank-row reconciliation via subset-sum matching

**Banking / bookkeeper**
- Bookkeeper export (ZIP per CPA requirements)
- External code fields on accounts + categories (CPA chart of accounts mapping)
- Period lock (prevents edits to locked periods)

### Life

**Tasks**
- Priority + state (open/waiting/done/cancelled), due date, completion timestamp
- Assigned user
- Linked subjects (polymorphic N:M to 11+ entity types)
- Media, notes, tags

**Meetings**
- Starts/ends, location, state (scheduled/completed/cancelled)
- Polymorphic subjects

**Contacts**
- Kind (person/organization), display_name, first/last/organization
- Emails, phones, addresses (structured JSON + Nominatim autocomplete)
- Favorites, vendor/customer flags, tax_id
- Party-role pivot to Contracts + polymorphic pivots elsewhere

**Calendar**
- Month view combining tasks + meetings + appointments + bill projections + document expirations + contract end dates
- Event pills clickable into Inspector

**Weekly review**
- Cross-domain digest of actionable items (overdue, due-this-week, expiring-soon)
- "Rituals this week" section showing per-template completion rate

**Checklists** (recurring rituals)
- Templates with ordered items, RRULE-based schedule (daily / weekdays / weekends / custom), time-of-day bucket (morning/midday/evening/night/anytime)
- Lazy per-day runs: ChecklistRun created on first tick / done / skip; JSON ticked-item array on the row — no pivot
- Auto-complete when every active item is ticked; explicit "Done" + "Skip today" controls; undo clears the run
- Streak counter per template, history heat-strip over the last 60 days
- Dashboard tile, mobile home inline toggles, attention-radar "Unfinished morning/evening routine" (after 11:00 / 22:00 local)
- Index page with Templates / History tabs at `/life/checklists`; focus view at `/life/checklists/today`

### Time

**Projects + time entries**
- Per-user projects with billable flag + hourly rate
- Time entries with start/end/duration
- Timer state machine (start / pause / stop / discard)
- Keyboard shortcut (backslash)

### Relationships

**Contracts**
- Kind (lease/subscription/insurance/service/warranty/other)
- Start/end dates, trial end, auto-renew flag, renewal notice days
- Monthly cost (amount + currency)
- State (active/expiring/ended/cancelled)
- Cancellation URL + email fields

**Insurance policies**
- Linked to parent Contract
- Coverage kind, policy number, carrier
- Premium (amount + cadence), coverage amount, deductible
- Covered subject (vehicle / property / person via polymorphic)

### Records

**Documents**
- Kind (passport/license/certificate/policy/other)
- Number, issued_on, expires_on
- Holder user, in-case-of-pack flag
- Attached Media

**Media**
- Files stored on local or public disk; attached polymorphically via `mediables` with role + position
- OCR status tracking (pending/running/done/failed)
- Image thumbnails, dimensions
- Deduplicated by sha256 hash
- Every download gated through `MediaFileController` (household ACL enforced)

**Notes**
- Freeform text with markdown
- Linked subjects (polymorphic)
- Tags

**Mail**
- Inbound via Postmark webhook + Gmail OAuth + Fastmail JMAP
- Per-account label/folder config
- Synthetic Media rows for HTML-body bills
- Chain derivation to records (transactions, contracts) via mediables

**Online accounts**
- Credentials NOT stored (Bureau is not a password manager); only metadata
- MFA method, 2FA flag
- Importance tier
- Linked subjects

**Tags + tag hub**
- Slug-based tags with household scope
- Tag hub `/tags/{slug}` showing everything tagged

**In-case-of pack**
- Curated view of documents + online accounts flagged `in_case_of_pack`

### Assets

**Properties**
- Kind (home/rental/land), structured address, acquired date, purchase price
- Size + unit (sqft, sqm, acres, hectares)
- Asset valuations over time
- Polymorphic media + notes + tasks

**Vehicles**
- Make/model/year, VIN, license plate + jurisdiction, odometer + unit
- Primary user, acquired/disposed dates
- Registration fee tracking
- Asset valuations

**Inventory**
- Category, location (sticky on mobile), purchased/disposed dates
- Photo gallery
- For-sale toggle + listing (asking price + platform + URL)
- Disposition tracking (sold/donated/lost/trashed)

### Health

**Providers** — name, specialty, primary (GP/dentist/vision/etc.)
**Prescriptions** — medication, dose, frequency, refills remaining
**Appointments** — provider, purpose, datetime, subject (polymorphic), state

### Alerts & automation

**Attention radar** (dashboard + mobile home)
- Overdue tasks, unreconciled transactions, overdue bills (with autopay grace)
- Pending reminders, trials ending ≤ 7d, auto-renewing contracts ending ≤ 14d with cancel link
- Gift cards expiring ≤ 30d, bills inbox (unprocessed scans), unprocessed inventory
- Budget envelopes ≥ 80% used, unusual charges ≤ 7d
- Savings goals hit target

**Money radar**
- Net worth + 30d monthly rollups
- Month-to-date cashflow + 30d obligations
- Active subscriptions total + monthly forecast
- Deterministic 30d / 90d forecast

**Reminders**
- Generated from date-bearing records (`reminders:generate` daily)
- Multi-channel (in_app / email / slack / sms / telegram / push)
- Per-user notification preferences
- `reminders:fire` every 5 min

**Weekly digest email**
- Sundays 17:00 via `digest:weekly`
- What changed (new transactions, completed tasks)
- What's coming (tasks, bills, auto-renewing contracts, active subscriptions)

### Auth & security

- **Password login** (Livewire-driven form)
- **Magic link** passwordless sign-in (15-min signed URLs, rate-limited)
- **Social OAuth** (Google / GitHub / Microsoft / Apple via Socialite) — no auto-provisioning
- **WebAuthn passkeys** (laragear/webauthn) — registration + sign-in + per-device management on profile
- **Login IP audit** (every password/magic-link/social/passkey attempt tracked; shown on profile)
- **Security headers middleware** (X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, COOP, CSP)
- **Per-request CSP nonce** via `CspNonce` middleware; Vite + Livewire + inline theme-resolvers emit matching nonces
- **Subresource Integrity** via inline Vite plugin writing sha384 hashes to manifest
- **Encrypted credentials** on Integration model
- **CSRF exception** for `/webhooks/*` only
- **Webhook auth fails closed** outside local/testing
- **Deploy hardening** — `.env` perms enforced, public/storage symlink check, `.env` gitignored check, verified gzip backups, `subscriptions:resume-due`-style nightly crons, ImageMagick PDF-coder lockdown

### Mobile PWA (`/m/*`)

- Dashboard (glance)
- Quick capture (inventory photo, note, generic photo)
- Inbox (unprocessed scans, bulk process)
- Global search
- Profile shortcut (me)
- Safe-area aware layout + theme-color meta
- Service worker + web manifest

### Settings

- `/settings` app-level preferences page (separate from `/profile` per-user)
- Notification preferences matrix (kind × channel) — in_app / email / push / telegram
- Connected integrations list with disconnect (Gmail / PayPal / Postmark / etc.)
- Backups status + "back up now" trigger (uses `backup:run --only-db`)
- Pointer to `/profile` for name / locale / theme / currency (no duplication)

### Platform / infra

- Laravel 13, Livewire 4, Vite 8, MariaDB
- UTC-in-DB + per-user locale/timezone/format settings
- Household scope trait applied to every domain model
- i18n via `__()` — en JSON starter file
- WCAG 2.1 AA baseline (semantic HTML, focus-visible, 4.5:1 contrast, reduced-motion)
- Theme tokens (dark / light / retro) with per-theme color remapping
- `composer check` gate (Pint + PHPStan L6 + Pest)
- Inspector SFC handling 16 entity types + polymorphic subjects
- Address autocomplete proxy (OSM Nominatim) with 24h cache + rate limit
- Spatie backup nightly with monitor
- OWASP ZAP: baseline + full + authenticated; GitHub Actions weekly scan; runbook in `docs/security/zap.md`

### Testing

- Pest + Playwright
- 519 feature tests covering auth, subscriptions, budgets, category/tag rules, savings goals, receipts, OCR, parsers, recurring discovery, address autocomplete, security headers, webhook auth, milestone tracker, YoY, login events, weekly digest, smoke tests for every route
- `composer test:refresh` rebuilds schema

---

## Roadmap

Split by rough effort. Mark shipped items by deleting them, not striking through.

**Origin tags** (so the roadmap stays honest about where ideas came from):
- `[user]` — asked for directly
- `[derived]` — directly extends something you asked for
- `[inferred]` — my inference from generic app patterns; treat as a menu, not a plan
- `[internal]` — refactor / tooling / security; no user-visible feature
- `[blocked]` — waiting on external unblock

### XS — ≤15 min

- `[internal]` `<x-ui.data-table>`, `<x-ui.filter-bar>`, `<x-ui.row-badge>` applied to more pages (started — some done)
- `[inferred]` Mobile PWA smoke-test assertions on key text (not just 200)
- `[inferred]` Attention radar: port remaining text-only tiles to links
- `[internal]` Filter-bar idiom standardization (toolbar vs form-wrapped — pick one)

### S — ≤30 min

- `[inferred]` Inline category edit on transactions list (dropdown on row, no Inspector)
- `[inferred]` Attention radar: per-tile snooze / dismiss (7-day)
- `[inferred]` Subscription inspector hint when rule amount drifts from cached monthly
- `[inferred]` Account balance sparkline on accounts-index rows
- `[inferred]` Actionable group on attention radar (mark reconciled / dismiss reminder without navigating)
- `[internal]` Pre-commit hook for `composer pint + test` on staged PHP
- `[derived]` Retro → light theme visual sweep in mobile PWA + inspector internals

### M — couple hours

- `[inferred]` Warranty / service expiration tracking on inventory (reuse reminder pattern)
- `[inferred]` Mobile PWA offline mode (service-worker cache for capture flows)
- `[derived]` JSON export/import for full household (M13) — foundation for primary↔replica sync
- `[inferred]` Dashboard tile reordering (drag-to-customize, stored on `users.dashboard_preferences`)
- `[inferred]` In-app push notifications via web-push-php
- `[inferred]` Onboarding CSV → seed `budget_caps` + `category_rules`
- `[inferred]` Simple currency conversion (rate table + converter for cross-currency rules/accounts)
- `[internal]` i18n string extraction to JSON (some `__()` keys not yet collected)

### L — days

- `[internal]` Livewire/Alpine CSP bundles — drop `unsafe-eval` + `style-src 'unsafe-inline'` (kills last 2 Medium ZAP findings)
- `[user]` `[blocked]` Plaid integration (waiting on sandbox credentials)
- `[inferred]` Multi-user household invite flow
- `[internal]` Native PHP enum migration (`Enums.php` → typed enums per domain)
- `[internal]` Inspector split per-entity (bundle weight + lazy load)
- `[user]` `[blocked]` OCR Tier 2 structured extraction at production speed (4090 hardware)
- `[user]` `[blocked]` MCP assistant (local LLM bound to household data)

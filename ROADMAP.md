# Bureau — Roadmap

Personal-affairs management app. **Primary purpose: grasp the big picture of life state** — synthesis, dashboards, timelines. Detail capture is a means, not the end.

Stack: Laravel 13 · Livewire 4 · Vite 8 · MariaDB · stancl/tenancy (single-DB v1 → DB-per-tenant at SaaS).

Status legend: ✅ in v1 schema · 🏗 v1 wiring (post-schema) · 🧭 deferred, schema-reserved · 💡 brainstormed, no schema yet.

---

## v1 schema — what's being built now

### Platform (tenancy, cross-cutting)
- ✅ Households, users, household_user pivot (row-level tenancy via `household_id`)
- ✅ Tags + polymorphic `taggables` (cross-domain tagging from day one — e.g. `#tax-2026`)
- ✅ Media + polymorphic `mediables` (one scan links to many entities)
- ✅ Notes (quick capture, pinned, private flag)
- ✅ Reminders (polymorphic, multi-channel enum)
- ✅ events_stream (denormalized cross-domain timeline read-model)
- ✅ Snapshots (polymorphic, for net-worth / monthly-rollup history)
- ✅ user_notification_preferences
- ✅ Integrations (provider credentials, encrypted)
- ✅ media_folders (rescan targets)
- ✅ fx_rates (multi-currency)

### Fiscal (hybrid accounting)
- ✅ Accounts (bank / credit / cash / investment / loan / mortgage)
- ✅ loan_terms (1:1 side table for loan/mortgage accounts — principal, rate, maturity)
- ✅ account_balances (daily snapshot rows → net-worth line)
- ✅ Categories (expense / income / transfer kind)
- ✅ Transactions (signed amount, currency, category, counterparty)
- ✅ Transfers (dedicated table — from/to account, from/to amount, from/to currency)
- ✅ recurring_rules (RFC-5545 RRULE, unifies bills + income + transfers + maintenance + warranties)
- ✅ recurring_projections (materialized upcoming occurrences → time radar)

### Calendar
- ✅ Tasks (priority, state, parent_task)
- ✅ Meetings (with attendees via `meeting_contact`)
- ✅ calendar_feeds (ICS subscription read-only v1)

### Relationships
- ✅ Contacts (person or org; phones/emails/addresses as JSON)
- ✅ Contracts (agreement / policy / subscription / employment / lease / other)
- ✅ contact_contract pivot
- ✅ insurance_policies (1:1 side table on insurance-kind contracts)
- ✅ insurance_policy_subjects (polymorphic — covers vehicles, properties, users)

### Assets (non-financial)
- ✅ Properties (home / rental / land / vacation)
- ✅ Vehicles (car / motorcycle / bicycle / boat)
- ✅ inventory_items (household belongings, with warranty_expires_on)
- ✅ asset_valuations (polymorphic — property + vehicle + inventory participate in net-worth)

### Identity & legal
- ✅ Documents (passport / license / ID / will / POA / insurance — with `expires_on` feeding attention radar)

### Health (stubbed)
- ✅ health_providers (→ contact + specialty)
- ✅ Prescriptions (subject user, schedule, refills_left)
- ✅ Appointments (→ provider, subject user)

### Ingestion
- ✅ mail_ingest_inbox (forward-to-address for receipts/bills)
- ✅ mail_messages (parsed inbound)
- ✅ mail_attachments (link to media)

### Time tracker (personal, adapted from nfp)
- ✅ projects (billable, hourly_rate, client_contact, per-user)
- ✅ time_entries (historical, duration_seconds, activity_date, billable flag)
- ✅ time_tracker_sessions (one live session per user; start/pause/resume/stop; quantize-on-stop)
- ✅ Top-bar Livewire widget (start / pause / resume / stop / discard); dashboard tile for today & this-week hours

### User profile (per-user preferences)
- ✅ `users.locale`, `users.timezone`, `users.date_format`, `users.time_format`, `users.week_starts_on`
- ✅ `ApplyUserPreferences` middleware sets `app()->setLocale()` per request
- ✅ `App\Support\Formatting` helpers (date / time / datetime / money) read current user's profile

---

## v1 wiring — after schema, still in scope

- ✅ Dark-theme shell layout (sidebar + header + time-tracker widget in top bar)
- ✅ Minimal password auth (Livewire Login SFC, POST logout, EnsureHousehold middleware)
- ✅ Dashboard home at `/` with 5 radar tiles (Money, Time, Commitments, Documents, Attention) + Time tracker tile — each powered by real queries against the scoped tenant
- ✅ Demo data seeder (accounts, transactions, transfers, contracts, insurance, property, vehicle, valuations, tasks, meetings, documents, recurring rules+projections, projects, time entries)
- ✅ Pest 4 feature + unit tests (auth redirect, login, dashboard renders, logout, tenancy scope, TimeTrackerSession elapsed math)
- ✅ Playwright smoke e2e (login page renders, unauth redirects, authenticated dashboard visible)
- ✅ Larastan / PHPStan level 6 wired; baseline committed and shrinks-only
- ✅ Laravel Pint formatting gate
- ✅ `lang/en.json` + UI strings wrapped in `__()` (navigation, login, layout — expanding over time)
- ✅ WCAG 2.1 AA basics: `<html lang>`, semantic landmarks (`<main>`, `<nav>`), skip-to-main link, focus-visible rings, `aria-current`, `aria-label`, `aria-invalid` + `aria-describedby` on form errors
- 🏗 Postmark inbound webhook → creates `mail_messages` + draft transaction/media
- 🏗 RRULE projection generator (artisan command + scheduled job)
- 🏗 MediaFolder rescan artisan command (discover files → create media)
- 🏗 spatie/laravel-backup config → nightly DB dump + media sync, retention GFS
- 🏗 Monthly `snapshots` generator (net-worth rollup into snapshots table)
- 🏗 Drill-down screens for each domain (list + detail + capture form); today they are "coming soon" stubs
- 🏗 **Header — global controls** (Livewire SFCs in the top bar, keyboard-shortcut-driven):
  - Global search (⌘/Ctrl+K) — command-palette style across domains: contacts, transactions, tasks, contracts, documents, notes, media. Fuzzy match over titles + descriptions + tags; result groups by domain; keyboard nav; Enter opens detail.
  - Quick add (⌘/Ctrl+.) — modal with a type picker (transaction, task, contact, note, meeting, document, time entry) then a minimal form per type. The same entry point used from any screen.
  - Alerts bell — dropdown of pending reminders + overdue items + recently-fired events; badge count from the attention radar's sum; mark-as-read / dismiss; "see all" → attention radar drill-down. Live-updates via Livewire poll or broadcast.
- 🏗 **User dropdown in header** (replace current sidebar user-card + Sign-out affordance; that footer slot goes back to sidebar content):
  - Avatar + name trigger in top-right; opens a menu.
  - **Profile** → profile editor (locale, timezone, date/time formats, notification prefs).
  - **Theme** → Light / Dark / System selector; persists on `users.theme` (schema TBD) and in `localStorage` for pre-auth pages.
  - **Language** → locale selector backed by `users.locale`; immediate page reload with the new locale active.
  - **Sign out** → POST `/logout`.
- 🏗 Profile editor surface (locale, timezone, date/time formats, notification prefs)
- 🏗 Full `__()` coverage across radar tiles and time-tracker widget; translations added as locales arrive
- 🏗 WCAG deep audit — axe-core in Playwright, manual keyboard walkthrough, Lighthouse ≥ 95 on dashboard
- 🏗 Shrink the PHPStan L6 baseline (fix real type issues, especially `CurrentHousehold::get()` Eloquent-model-vs-Household return type, Formatting nullsafe warnings, seed-helper iterable type)

---

## Infrastructure trajectory

- ✅ Single-DB tenancy now (every row carries `household_id`; `BelongsToHousehold` trait)
- 🧭 Family-sharing: same DB, multiple users per household, ACL refinement
- 🧭 SaaS (DB-per-family): re-enable stancl/tenancy bootstrappers, move tenant migrations to `database/migrations/tenant/`, add domain-based tenant identification
- 🧭 Per-entity ACL (e.g. spouse sees joint accounts but not private journal) — `private` bool reserved on sensitive models (Note, health, journal)

---

## Authentication & identity

Standard Laravel password auth ships in v1. Everything else is deferred.

- 🧭 **Social login (OAuth)** — Laravel Socialite with Google, Apple, GitHub, Microsoft. "Sign in with Apple" is table stakes for iOS users. Sign-in is distinct from email ingestion OAuth (`integrations` table); keep them cleanly separated.
- 🧭 **Magic link login** — passwordless email links. Laravel has it via `Auth::loginUsingId()` + signed URLs; `laravel/fortify` bundles a ready flow. Useful for a family member who rarely logs in.
- 🧭 **2FA** — TOTP (Google Authenticator / 1Password) via `laravel/fortify`. Recovery codes. Enforced per-household-role (owners required, members optional).
- 🧭 **Passkeys (WebAuthn)** — phase-in after 2FA. `laravel/fortify` + a WebAuthn driver. Biometric unlock on iOS/Android is the end-state for a personal app with sensitive data.
- 🧭 **Active session management** — "see devices currently logged in, revoke any" — Laravel ships this in Fortify.
- 🧭 **Login audit log** — `auth_events` (user_id, event: login|failed|mfa_challenge|password_reset, ip, user_agent, at) — feeds attention radar when something unusual shows up.
- 🧭 **Security-sensitive-action reverification** — viewing/editing the "in case of" pack or integration credentials requires a fresh password / biometric challenge.

---

## Cross-cutting aspects — high-leverage, build roadmap

Ordered by ROI for the big-picture goal.

1. 🏗 **Unified timeline + upcoming-obligations feed** — the time radar. Powered by `events_stream` + `recurring_projections`. Highest-leverage synthesis surface.
2. 🏗 **Net-worth snapshots + cashflow rollups** — the money radar. Accounts + asset_valuations + transactions + transfers, joined into monthly snapshots.
3. ✅ **Cross-domain tags** — schema in v1; UX deferred.
4. 🏗 **Reminders engine** — multi-channel delivery (email, Slack, SMS, push, in-app, Telegram). Feeds attention radar.
5. 🧭 **OCR + full-text search on media** — turns the scan pile into something queryable. Meilisearch or Typesense alongside MariaDB; OCR via Tesseract. Biggest retroactive force-multiplier.
6. 🧭 **Email ingestion beyond Postmark** — Gmail API (OAuth) for existing-history scanning; IMAP/JMAP for Fastmail.
7. 🧭 **Bank CSV/OFX import** — eliminate manual transaction entry. Plaid/SaltEdge as a later, heavier step.
8. 🧭 **Automations / rules engine** — "when transaction matches merchant Y, auto-category Z"; "when contract expires in 30 days, create task"; event-driven side effects.
9. 🧭 **Dashboards (per-domain radars + one unified "state of affairs")** — the primary UX. Drill-downs come off the dashboard.
10. 🧭 **Timeline view** ("what happened on this date last year") — across all domains.
11. 🧭 **Mobile capture** — snap receipt → pending transaction + media; voice note → Note. Web-first, PWA later.
12. 🧭 **Sharing / partial visibility** — read-only spouse access to selected domains.
13. 🧭 **Encryption at rest for media** + strong backup story (off-site, encrypted).
14. 🧭 **Weekly review prompt** — surfaces orphan tasks, unreconciled transactions, stale contracts, expiring documents.
15. 🧭 **Forecast / prognosis engine** — project future income, spending, and balance from historical data + seasonality.
    - **Deterministic layer** — `recurring_projections` already gives a known-future view for bills, income, transfers, maintenance. Sum forward across accounts → guaranteed-cashflow line.
    - **Probabilistic layer** — learn from historical transactions to estimate non-recurring spending by category and month-of-year: moving averages per category, seasonality decomposition (December gift spike, summer travel, Q1 tax season), day-of-week patterns where relevant. Simple statistical methods (STL decomposition, exponential smoothing, or Prophet for richer cases) — no ML needed for v1.
    - **Outputs** stored in `snapshots` (kind=`forecast`, payload = point estimate + confidence band, generated daily or on-demand):
      - End-of-month balance per account
      - 30/60/90-day cashflow prognosis (net in/out by week)
      - Year-end net-worth projection
      - Per-category spending forecast vs. typical
    - **Surfaces it drives**:
      - "If the next 30 days behave like the last 12 months, you'll end the month at $X" tile on the money radar.
      - Anomaly flags: "grocery spend is 40% above your seasonal baseline."
      - Runway estimates: "at current burn, liquid savings last N months if income stops."
      - Seasonal awareness: roadmap view showing expected expensive months ahead.
    - **Back-test column on `snapshots`** — when a forecast date arrives, store actual vs. forecast so accuracy improves over time (and surfaces as "forecast confidence" on the dashboard).

---

## New domains — deferred expansion

Each has a target shape; listed in rough priority based on personal-admin load.

### Taxes
- 💡 `tax_years` (year, jurisdiction, filing_status, state, filed_on, refund_amount)
- 💡 `tax_documents` (→ tax_year, kind: W2/1099/K1/receipt, source contact, media)
- 💡 `tax_estimated_payments` (quarterly projection rows)
- Drives tax-season radar. Tags do most of the work in v1 (`#tax-2026`).

### Home / property maintenance
- 💡 `maintenance_schedules` — falls out of `recurring_rules` with `subject_type=Property`. No new table needed; seed templates below.
- 💡 **Seed template: recurring home-maintenance rules**, each becomes a `recurring_rule` attached to a property, generates projections, and surfaces on the attention radar:
  - HVAC air filter replacement — every 1–3 months (varies by filter rating, pets, allergies)
  - Whole-house water filter cartridge — every 3–6 months
  - Fridge water/ice filter — every 6 months
  - Furnace annual service — yearly, before heating season
  - AC service — yearly, before cooling season
  - Water heater flush — yearly
  - Dryer vent cleaning — yearly
  - Gutter cleaning — biannual (spring + fall)
  - Roof inspection — yearly
  - Smoke / CO detector battery + test — yearly (or device-specified interval)
  - Chimney sweep — yearly if wood-burning
  - Pest control — quarterly if on a plan
  - Septic pump-out — every 3–5 years
  - Deck / fence staining — every 2–3 years
  - Water softener salt refill — monthly-ish (could track via a meter reading instead)
- 💡 `meter_readings` (property_id, kind: electric/gas/water/oil, read_on, unit, value) — drives consumption dashboards and catches leaks.
- 💡 `home_projects` (renovation budgets → linked transactions, contractor contacts, start/end, before/after media)

### Vehicles — expanded
- 💡 `vehicle_service_log` (odometer, service_kind, date, cost, provider_contact)
- 💡 `vehicle_fuel_log` — optional; likely skippable in favor of tagged transactions.

### Investments — detailed
- 💡 `holdings` (→ investment account, symbol, quantity, cost_basis, as_of) — manual snapshots; no live market feed v1.
- 💡 `dividends` / `corporate_actions` — deferred.

### Family & household
- 💡 `family_members` — first-class (not just contacts); roles (spouse, child, ward). Kids get schools + health attached.
- 💡 `pets` (species, breed, date_of_birth, color, microchip_id, primary_owner_user_id, vet_provider_id, notes, photo)
- 💡 `pet_vaccinations` (pet_id, vaccine_name, administered_on, valid_until, booster_due_on, administered_by_provider_id, proof_media_id) — mandatory vaccines with `valid_until` feed the attention radar. Species-specific templates seeded at pet creation:
  - **Dogs** — Rabies (legally required in most jurisdictions, 1–3 year validity), DHPP / DAPP (distemper, adenovirus, parvo, parainfluenza), Bordetella (if boarding / daycare), Leptospirosis (regional), Lyme (tick-risk regions), Canine Influenza (situational)
  - **Cats** — Rabies (legally required in most jurisdictions), FVRCP (feline distemper combo), FeLV (especially outdoor or multi-cat households)
  - Other species (rabbits, ferrets, etc.) — species-specific templates.
- 💡 `pet_preventive_care` — flea/tick/heartworm/dewormer schedules. Falls out of `recurring_rules` with `subject_type=Pet`, no new table needed beyond templates.
- 💡 `pet_licenses` — as `documents` rows with `kind=pet_license`, `expires_on` → attention radar.
- 💡 Pet medications (chronic) — `prescriptions` already supports a subject; extend subject to polymorphic (user OR pet) or add `subject_pet_id` alongside `subject_user_id`.
- 💡 `schools` + `school_terms` — for kids
- 💡 `events` (birthdays, anniversaries) — recurring_rules with kind=event

### Career / work
- 💡 `jobs_timeline` (employer contact, role, start, end, comp summary)
- 💡 `certifications` (kind, issuer, issued_on, expires_on — feeds attention radar)
- 💡 `continuing_education` (credits, provider, completed_on)
- 💡 If freelancing: `clients` (= contacts kind=org), `projects`, `invoices`, `time_entries`.

### Travel
- 💡 `trips` (title, starts_on, ends_on, primary_destination, budget)
- 💡 `trip_bookings` (trip_id, kind: flight/hotel/car/activity, confirmation, vendor contact, cost)
- 💡 `loyalty_programs` (carrier contact, member_number, status, points_balance)
- 💡 Passport/visa already under `documents`.

### Correspondence
- 💡 `physical_mail` (received_on, from contact, kind, scan media, summary, action_required)
- 💡 `followups` (waiting on X from contact Y, deadline) — arguably a tasks subtype

### Subscriptions
- ✅ Fall out of `contracts` (kind=subscription) + `recurring_rules`. Dedicated UI view, no new tables.
- 💡 `free_trials` (end_date, auto_converts_to_contract_id) — cancel-before-billing tracker.

### Journal & personal development
- 💡 `journal_entries` (user_id, entry_on, mood, body, private)
- 💡 `goals` (title, horizon, measurable_criteria, state, linked_task_ids)
- 💡 `habits` + `habit_logs` (recurring rule + daily checkmarks)
- 💡 `reading_list` / `media_log` (books, films, podcasts — with rating + completion state)

### Decisions & research
- 💡 `decisions` (what, when, why, alternatives, outcome_review) — retrospective value.
- 💡 `wishlists` (item, linked_url, target_price, price_history)

### "In case of" pack
- 🧭 Derived view, not a table — filtered render across documents + contracts + contacts + accounts marked for emergency access. Export-to-PDF for a sealed envelope.

---

## Integrations — roadmap

### Mail
- 🏗 Postmark inbound (v1) — forward-to-address, webhook parses, attaches media.
- 🧭 Gmail API (OAuth) — read-history, label-watching, thread context.
- 🧭 Fastmail JMAP — native integration.
- 🧭 IMAP (generic) — fallback for anything else.

### Calendar
- 🏗 ICS URL subscription (v1) — read-only pull of external calendars into meetings.
- 🧭 Google Calendar API — OAuth, write-back, conflict-aware sync.
- 🧭 CalDAV (Fastmail, Apple, Nextcloud).

### Notifications
- 🏗 Email (built-in)
- 🧭 Slack (webhook or app)
- 🧭 Telegram bot — cheap "mobile push" surface.
- 🧭 SMS via Twilio.
- 🧭 Web Push (v2+ if PWA matures).

### Banking
- 🧭 CSV / OFX import (first wave — manual per statement).
- 🧭 Plaid (US) or GoCardless Bank Account Data (EU) — automated feeds.
- 🧭 SaltEdge — alternative with broad geographic coverage.

### Accounting
- 🧭 No external accounting integration planned. In-app model borrows Firefly III's rigor (separate categories + dedicated transfers) without depending on its software.

---

## Big-picture surfaces (the point of the whole app)

### Radars (status dashboards — primary UX)
- 💡 **Money radar** — this-month cashflow bars, net-worth trend line, next-30-day obligations cumulative burn, top categories, anomalous transactions flagged.
- 💡 **Time radar** — today + next 7/30 days union of (tasks + meetings + bill projections + expiring documents + contract renewals + appointments). Overdue highlighted.
- 💡 **Commitments radar** — active contracts count, expiring windows (30/90/365), monthly-cost burn tied up in subscriptions/insurance/leases.
- 💡 **Documents radar** — recent scans, untagged/unfiled count, OCR-pending, upcoming doc expirations.
- 💡 **Attention radar** — what needs a decision this week: renewals, overdue tasks, unreconciled transactions, stale followups.
- 💡 **Forecast radar** — 30/60/90-day balance prognosis by account, projected end-of-month and end-of-year net worth, seasonal spending chart (this month vs. seasonal baseline), runway estimate (months of cash cover at current burn). Combines `recurring_projections` (deterministic) with historical-trend models. See the forecast engine in Cross-cutting aspects.
- 💡 **Household radar** — maintenance due, warranties expiring, inventory value by room, pet care due.
- 💡 **One unified "state of my life" dashboard** — pulls top tiles from every radar. Drill-downs off it.

### Cross-cutting views
- 💡 **Chronological timeline** — life-log: one scrollable stream across all domains, filterable by tag/domain/year. "This date last year."
- 💡 **Tag hub** — pick a tag (e.g., `#rental-property`), see every entity across domains.
- 💡 **Weekly review** — prompt-driven surface that forces attention to each radar in turn.

---

## Data stewardship

- 🏗 Backups: `spatie/laravel-backup` nightly DB dump + media sync to off-site encrypted storage (Backblaze B2 / S3); GFS retention.
- 🏗 Monthly `verify-restore` artisan command — restores latest dump to a temp DB, checks row counts.
- 🧭 Media encryption at rest (age/gpg of the file store, or Laravel disk-level encryption).
- 🧭 Export everything as a zip (DB dump + media + tagged manifest) — lifeboat for portability or recovery.
- 🧭 Audit log — `audits` table tracking mutations on sensitive models (documents, contracts, accounts).

---

## Guardrails (schema conventions — apply to every new domain)

- Canonical timeline column (`occurred_on` / `due_at` / `starts_at` / `effective_at` / `expires_on`) on anything that can appear in a radar.
- Money columns are always `amount` + `currency`; signed where direction matters.
- Status enums over free text for any field that will appear in a rollup dimension.
- Every tenant-scoped model uses `BelongsToHousehold` trait + `household_id` column.
- Polymorphic `taggables` + `mediables` — every domain model is taggable and mediable unless there's an affirmative reason not to be.
- Datetimes stored in UTC; local conversion at the presentation layer only.

---

## Explicitly out of scope

- Double-entry accounting rigor (business bookkeeping). Personal app, not QuickBooks.
- Live market feeds for investments. Manual snapshots only.
- Password vault. Use 1Password/Bitwarden; Bureau links to where things live, not the secrets themselves.
- Social/collaborative features beyond the household.
- Real-time sync across devices (use server-of-record + refresh; no CRDTs).

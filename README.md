# Secretaire

Personal-affairs app for a household of one. Secretaire is where I keep the
long tail of adult life — finances, bills, contracts, insurance, vehicles,
property, pets, health, taxes, reading, decisions, and whatever else needs
to be found later. It's built for me; you're welcome to read the code.

## Philosophy

- **Synthesis over capture.** Dashboards, radars, and timelines are the
  primary surface. Forms exist to feed them.
- **Single user, single household.** No multi-tenant plumbing, no RBAC
  matrices. The bookkeeper portal is the only non-owner audience and is
  deliberately read-only.
- **Local-first data with an online surface.** The database is the source
  of truth; the web UI is a typed-and-templated lens over it.
- **Features come from personal workflow.** If I don't actually use it
  every week or two, it doesn't ship. Competitor surveys stay deferred
  until the shape is settled.
- **Keyboard and one-hand mobile.** Every quick-add has a letter key;
  every listing survives `text-sm` as the comfort floor.

## Stack

- PHP 8.3 · Laravel 13 · Livewire 4 (class + Volt SFC) · Alpine.js
- Vite 8 · Tailwind CSS v4 · TypeScript
- MariaDB (app DB) · SQLite (not used; migrations target MariaDB dialect)
- Pest (feature + unit) · Playwright (browser) · PHPStan level 6 · Pint
- Tesseract for OCR on receipts/bills; inventory photos skip OCR by
  design. Local-LLM enrichment is deferred until the new GPU lands.

## What's inside

The app is organized by life-area. Rough inventory of shipped modules:

- **Money** — Accounts, transactions, ledger, bills (RecurringRule), budgets,
  savings goals, subscriptions, categories, recurring-pattern discovery,
  statement import (Citi/Costco/WF), reconciliation workbench, taxes
  (years, documents, estimated payments, preparer + bookkeeper contacts).
- **Life > Logs** — Journal entries, decisions (ADR-style), reading/watching
  media log, food log (with photo capture). Sibling day-logs under one hub.
- **Goals** — Finite targets (value + deadline, pace tracking) and infinite
  directions (cadence-based check-ins). Tasks, projects, and journal
  entries link to goals via the polymorphic subject system.
- **Schedule** — Tasks (with subtasks via parent_task_id), reminders,
  meetings, appointments, checklist templates.
- **Commitments** — Contracts, insurance policies.
- **Assets** — Properties, vehicles, inventory items, domains, meter
  readings, vehicle service log.
- **Records** — Documents, online accounts, notes, physical mail.
- **Health** — Appointments, prescriptions, health providers, pets
  (vaccinations, checkups, licenses, grooming cadence).
- **Time** — Projects, time entries, a header time-tracker widget.
- **Bookkeeper portal** — Token-gated read-only view for an external CPA,
  with period locks on closed months and an audit-trailable export.
- **Dashboards** — Attention radar (aggregates nudges across 20+ sources),
  money radar, finance overview, weekly review, weekly digest email.

## Local development

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
php artisan serve
```

Default credentials live in `DatabaseSeeder`. The single user's profile
carries locale/timezone/date + time format preferences; middleware applies
them per-request so the DB stays UTC and the UI renders local.

### Useful commands

```bash
composer test                  # Pest feature + unit suite
composer test:refresh          # rebuild the test schema (after migrations)
composer pint                  # format (required; CI blocks on drift)
composer phpstan               # static analysis at L6 (baseline may only shrink)
npx playwright test            # browser tests

php artisan subscriptions:backfill     # seed subscriptions from existing rules
php artisan subscriptions:regenerate   # destructive rebuild (confirms)
php artisan recurring:discover         # scan for new recurring patterns
```

## Themes

Five palettes shipped; all adapt via CSS custom properties, no dual markup.

- `dark` — default.
- `light` — inverted neutral scale + accent remap for WCAG AA on white.
- `dusk` — warm midtone stone, easier on the eyes for long sessions.
- `dusk-comfort` — dusk + `text-sm` floor (no text below 14px).
- `retro` — monospace, phosphor-glow accents, faint CRT scanlines.

Toggle via `/profile`; resolved synchronously pre-paint to avoid flash.

## Conventions

- Every user-facing string goes through `__()`; JSON lang files per locale.
- Every file destined to disk uses plain ASCII — no emojis in filenames.
- Currency is household-level (`Household::default_currency`); no
  per-field currency picker in forms.
- Downloads are always gated: auth + household scope + ACL on every media
  file, export, and backup.
- Outflow amounts are stored negative; displays show magnitude via
  explicit `abs()` at render time.
- Tests run with `DatabaseTransactions`; run `composer test:refresh`
  after a schema change.

## Deployment

Primary deploy target is a personal VPS behind Cloudflare. There's no
public registration flow. The bookkeeper portal is the only unauthenticated
entry point and it requires a pre-shared time-boxed token.

## License

Private. Source is here for reading and reference; reuse requires
permission.


  ┌────────────────────┬──────────────────────────────────────────────────────────────────────────────────────────────────────────────┐
  │        Task        │                                              Command / location                                              │
  ├────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Update your .env   │ APP_NAME=Secretaire, DB_DATABASE=secretaire, DB_USERNAME=secretaire, APP_URL=https://secretaire.aurnata.com  │
  ├────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Rename physical DB │ MariaDB doesn't RENAME DATABASE; create fresh secretaire DB + mysqldump bureau | mysql secretaire + drop old │
  ├────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Rename test DB     │ bureau_test → secretaire_test (then update phpunit.xml, .env.testing, and tests/Pest.php)                    │
  ├────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Rename GitHub repo │ bgurvich/bureau → bgurvich/secretaire; then git remote set-url origin git@github.com:bgurvich/secretaire.git │
  ├────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Rename working dir │ mv /home/moshe/bureau /home/moshe/secretaire (close editors first)                                           │
  ├────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Cloudflare DNS     │ secretaire.aurnata.com → your VPS                                                                            │
  ├────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Server migration   │ Rename Unix user bureau (or leave), rename nginx-bureau.conf, update install.sh defaults, re-run deploy      │
  └────────────────────┴──────────────────────────────────────────────────────────────────────────────────────────────────────────────┘

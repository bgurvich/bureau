<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\AssetValuation;
use App\Models\BudgetCap;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Household;
use App\Models\InsurancePolicy;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\Property;
use App\Models\RecurringRule;
use App\Models\SavingsGoal;
use App\Models\Tag;
use App\Models\TagRule;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\CurrentHousehold;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Seeds a household with *primary* facts — the ones a real user would type
 * into Bureau: accounts, contacts, transactions, the rules they set up.
 *
 * Everything *derived* is produced by Bureau's own engines, not
 * hardcoded in this file. That keeps demo data aligned with production
 * behavior — if an observer or command changes, the seed output changes
 * with it, and any drift is a genuine bug not a fixture discrepancy.
 *
 * Derivation pipeline:
 *
 *   1. Primary rows inserted here: Accounts, Contacts, Categories already
 *      seeded by StarterCategoriesSeeder, Transactions (uncategorized),
 *      RecurringRules, Contracts, CategoryRules, TagRules, SavingsGoals,
 *      BudgetCaps, Tasks, Meetings, Property, Vehicle, Documents,
 *      Projects + TimeEntries.
 *
 *   2. Observers fire during inserts:
 *      - RecurringRule::created → SubscriptionSync creates Subscription
 *      - Contract::created      → SubscriptionSync back-links by counterparty
 *      - Transaction::created   → CategoryRuleMatcher + TagRuleMatcher apply
 *
 *   3. Commands invoked at the end:
 *      - recurring:project      → materializes RecurringProjection rows
 *      - categories:apply       → retro-categorizes any missed transactions
 *      - subscriptions:backfill → safety net for rules pre-dating the observer
 *      - receipts:match         → pairs OCR'd receipts to transactions
 *      - recurring:discover     → RecurringDiscovery rows for unseen patterns
 *      - snapshots:rollup       → monthly net-worth + cashflow snapshots
 *      - savings:milestones     → fires reminders for goals past thresholds
 *
 * Idempotency guard: bails if Account::exists() in the current household.
 *
 * Usage:
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $household = CurrentHousehold::get() ?? Household::query()->orderBy('id')->first();
        if (! $household) {
            $this->command->warn('DemoDataSeeder: no household found — run DatabaseSeeder first.');

            return;
        }
        CurrentHousehold::set($household);

        $user = $household->users()->orderBy('users.id')->first() ?? User::query()->orderBy('id')->first();
        if (! $user) {
            $this->command->warn('DemoDataSeeder: no user found — run DatabaseSeeder first.');

            return;
        }

        if (Account::exists()) {
            $this->command->info('DemoDataSeeder: demo data already present — skipping.');

            return;
        }

        $this->command->info('DemoDataSeeder: seeding primary entities…');
        $this->seedPrimary($household, $user);

        $this->command->info('DemoDataSeeder: running derivation pipeline…');
        $this->runDerivations($household);
    }

    protected function seedPrimary(Household $household, User $user): void
    {
        // ── Contacts ─────────────────────────────────────────────────────
        $landlord = Contact::create(['kind' => 'org', 'display_name' => 'Bay View Properties']);
        $verizon = Contact::create(['kind' => 'org', 'display_name' => 'Verizon']);
        $netflix = Contact::create(['kind' => 'org', 'display_name' => 'Netflix']);
        $gym = Contact::create(['kind' => 'org', 'display_name' => 'Acme Gym']);
        $insurer = Contact::create(['kind' => 'org', 'display_name' => 'State Farm']);
        $employer = Contact::create(['kind' => 'org', 'display_name' => 'Acme Corp']);
        Contact::create(['kind' => 'person', 'display_name' => 'Dr. Sarah Chen', 'favorite' => true]);
        Contact::create(['kind' => 'person', 'display_name' => 'Mom']);

        // ── Accounts ─────────────────────────────────────────────────────
        $checking = Account::create([
            'type' => 'checking', 'name' => 'Chase Checking', 'institution' => 'Chase',
            'currency' => 'USD', 'opening_balance' => 5240.18,
        ]);
        $savings = Account::create([
            'type' => 'savings', 'name' => 'Chase Savings', 'institution' => 'Chase',
            'currency' => 'USD', 'opening_balance' => 18750.00,
        ]);
        $amex = Account::create([
            'type' => 'credit', 'name' => 'Amex Gold', 'institution' => 'American Express',
            'currency' => 'USD', 'opening_balance' => -842.30,
        ]);
        Account::create([
            'type' => 'investment', 'name' => 'Vanguard Brokerage', 'institution' => 'Vanguard',
            'currency' => 'USD', 'opening_balance' => 72430.55,
        ]);

        // ── Category + tag rules (observers fire on subsequent Transaction saves) ──
        foreach ([
            ['food/groceries', 'whole foods'],
            ['food/groceries', 'trader joe'],
            ['food/groceries', 'safeway'],
            ['food/groceries', 'costco'],
            ['transport/fuel', 'shell'],
            ['food/coffee', 'blue bottle'],
            ['food/dining-out', 'sushi'],
            ['food/dining-out', 'izakaya'],
            ['food/dining-out', 'thai'],
            ['housing/utilities', 'pg&e'],
            ['housing/utilities', 'sfpuc'],
            ['housing/rent-mortgage', 'rent payment'],
            ['housing/utilities', 'verizon'],
            ['subscriptions/media', 'netflix'],
            ['health/fitness', 'acme gym'],
            ['salary', 'payroll', 'income'],
            ['refunds', 'refund', 'income'],
        ] as $row) {
            [$slug, $pattern] = [$row[0], $row[1]];
            $kind = $row[2] ?? 'expense';
            if ($id = $this->catId($slug, $kind)) {
                CategoryRule::forceCreate([
                    'category_id' => $id,
                    'pattern_type' => 'contains',
                    'pattern' => $pattern,
                    'active' => true,
                ]);
            }
        }

        $coffeeTag = Tag::firstOrCreate(['slug' => 'coffee'], ['name' => 'coffee']);
        $transferTag = Tag::firstOrCreate(['slug' => 'transfer'], ['name' => 'transfer']);
        TagRule::forceCreate(['tag_id' => $coffeeTag->id, 'pattern_type' => 'contains', 'pattern' => 'coffee', 'active' => true]);
        TagRule::forceCreate(['tag_id' => $transferTag->id, 'pattern_type' => 'contains', 'pattern' => 'autopay', 'active' => true]);

        // ── Transactions (last ~5 months so discovery has signal) ────────
        //
        // Categories are intentionally left blank on these inserts — the
        // TransactionObserver hooks CategoryRuleMatcher + TagRuleMatcher and
        // fills them in. Same code path real user transactions go through.
        //
        // Format: [daysAgo, Account, signedAmount, description, counterparty?]
        $history = [];

        // Rent — 5 months
        for ($m = 0; $m < 5; $m++) {
            $history[] = [$m * 30 + 28, $checking, -2200, 'Rent payment', $landlord->id];
        }
        // Salary — 1st + 15th for 5 months
        for ($m = 0; $m < 5; $m++) {
            $history[] = [$m * 30 + 15, $checking, 4850, 'Acme Corp payroll', $employer->id];
            $history[] = [$m * 30 + 1,  $checking, 4850, 'Acme Corp payroll', $employer->id];
        }
        // Subscription trio — 5 months each
        for ($m = 0; $m < 5; $m++) {
            $history[] = [$m * 30 + 12, $amex, -15.49, 'Netflix', $netflix->id];
            $history[] = [$m * 30 + 5,  $amex, -60.00, 'Verizon Wireless', $verizon->id];
            $history[] = [$m * 30 + 3,  $amex, -35.00, 'Acme Gym', $gym->id];
        }
        // Groceries — weekly-ish with variation
        $groceryStops = [
            [2, -84.12, 'Whole Foods'], [9, -112.44, 'Trader Joe\'s'],
            [16, -76.80, 'Safeway'], [23, -98.50, 'Whole Foods'], [30, -64.11, 'Costco'],
        ];
        foreach (range(0, 4) as $m) {
            foreach ($groceryStops as [$day, $amt, $store]) {
                $jitter = (($m * 3.17 + $day) * 0.5);
                $history[] = [$m * 30 + $day, $amex, $amt - $jitter, $store, null];
            }
        }
        // Dining + coffee
        $dining = [
            [6, -42.80, 'Sushi Ran'], [13, -18.50, 'Blue Bottle Coffee'],
            [20, -95.20, 'Izakaya'], [27, -22.00, 'Thai lunch'],
        ];
        foreach (range(0, 4) as $m) {
            foreach ($dining as [$day, $amt, $where]) {
                $history[] = [$m * 30 + $day, $amex, $amt, $where, null];
            }
        }
        // Fuel — every 2 weeks
        foreach (range(0, 9) as $i) {
            $history[] = [$i * 14 + 4, $amex, -50.00 - ($i % 3) * 2.5, 'Shell gas', null];
        }
        // Utilities — monthly
        foreach (range(0, 4) as $m) {
            $history[] = [$m * 30 + 11, $checking, -135.40, 'PG&E electric', null];
            $history[] = [$m * 30 + 11, $checking, -68.20, 'SFPUC water', null];
        }
        // Outlier: anniversary dinner (anomaly detector should flag)
        $history[] = [1, $amex, -385.00, 'Anniversary dinner at Izakaya', null];
        // Refund
        $history[] = [7, $checking, 45.00, 'Amazon refund', null];
        // Amex autopay — monthly reconciling pair (TagRule attaches "transfer")
        foreach (range(0, 4) as $m) {
            $history[] = [$m * 30 + 25, $checking, -1420.50, 'Amex autopay', null];
            $history[] = [$m * 30 + 25, $amex,      1420.50, 'Amex autopay', null];
        }

        foreach ($history as [$daysAgo, $acct, $amount, $desc, $cpId]) {
            Transaction::create([
                'account_id' => $acct->id,
                'counterparty_contact_id' => $cpId,
                'occurred_on' => now()->subDays($daysAgo)->toDateString(),
                'amount' => $amount,
                'currency' => 'USD',
                'description' => $desc,
                'status' => 'cleared',
                'reconciled_at' => now(),
            ]);
        }

        // One pending transaction for the attention radar to pick up.
        Transaction::create([
            'account_id' => $amex->id,
            'occurred_on' => now()->subDays(1)->toDateString(),
            'amount' => -128.40, 'currency' => 'USD',
            'description' => 'Flowers (pending)', 'status' => 'pending',
            'reconciled_at' => now(),
        ]);

        // ── Transfers ────────────────────────────────────────────────────
        Transfer::create([
            'occurred_on' => now()->subDays(15)->toDateString(),
            'from_account_id' => $checking->id,
            'from_amount' => 1000, 'from_currency' => 'USD',
            'to_account_id' => $savings->id,
            'to_amount' => 1000, 'to_currency' => 'USD',
            'description' => 'Monthly savings', 'status' => 'cleared',
        ]);

        // ── Property + Vehicle + Valuations ──────────────────────────────
        $home = Property::create([
            'kind' => 'home', 'name' => 'SF Apartment',
            'address' => ['line1' => '123 Main St', 'city' => 'San Francisco', 'region' => 'CA', 'postcode' => '94110', 'country' => 'US'],
            'acquired_on' => now()->subYears(3)->toDateString(),
            'purchase_price' => 880000, 'purchase_currency' => 'USD',
        ]);
        AssetValuation::create([
            'valuable_type' => Property::class, 'valuable_id' => $home->id,
            'as_of' => now()->toDateString(), 'value' => 925000, 'currency' => 'USD',
            'method' => 'estimate', 'source' => 'Zillow',
        ]);

        $car = Vehicle::create([
            'kind' => 'car', 'make' => 'Honda', 'model' => 'Civic', 'year' => 2019,
            'license_plate' => '8KLM123', 'license_jurisdiction' => 'CA',
            'acquired_on' => now()->subYears(2)->toDateString(),
            'purchase_price' => 22000, 'purchase_currency' => 'USD',
            'primary_user_id' => $user->id, 'odometer' => 41200,
        ]);
        AssetValuation::create([
            'valuable_type' => Vehicle::class, 'valuable_id' => $car->id,
            'as_of' => now()->toDateString(), 'value' => 14800, 'currency' => 'USD',
            'method' => 'estimate', 'source' => 'KBB',
        ]);

        // ── Recurring rules (observer auto-creates Subscription rows) ────
        RecurringRule::create([
            'kind' => 'bill', 'title' => 'Rent',
            'amount' => -2200, 'currency' => 'USD',
            'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
            'dtstart' => now()->subYear()->startOfMonth()->toDateString(),
            'account_id' => $checking->id,
            'counterparty_contact_id' => $landlord->id,
            'category_id' => $this->catId('housing/rent-mortgage'),
            'lead_days' => 5,
        ]);
        RecurringRule::create([
            'kind' => 'bill', 'title' => 'Netflix',
            'amount' => -15.49, 'currency' => 'USD',
            'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=12',
            'dtstart' => now()->subYears(3)->toDateString(),
            'account_id' => $amex->id,
            'counterparty_contact_id' => $netflix->id,
            'category_id' => $this->catId('subscriptions/media'),
        ]);
        RecurringRule::create([
            'kind' => 'bill', 'title' => 'Verizon phone',
            'amount' => -60, 'currency' => 'USD',
            'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=5',
            'dtstart' => now()->subYear()->toDateString(),
            'account_id' => $amex->id,
            'counterparty_contact_id' => $verizon->id,
            'category_id' => $this->catId('housing/utilities'),
        ]);
        RecurringRule::create([
            'kind' => 'income', 'title' => 'Acme Corp payroll',
            'amount' => 4850, 'currency' => 'USD',
            'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1,15',
            'dtstart' => now()->subYear()->toDateString(),
            'account_id' => $checking->id,
            'counterparty_contact_id' => $employer->id,
            'category_id' => $this->catId('salary', 'income'),
        ]);
        RecurringRule::create([
            'kind' => 'maintenance', 'title' => 'Change HVAC filter',
            'rrule' => 'FREQ=MONTHLY;INTERVAL=3',
            'dtstart' => now()->subMonths(2)->toDateString(),
            'subject_type' => Property::class, 'subject_id' => $home->id,
            'lead_days' => 7,
        ]);

        // ── Contracts (observer back-links Subscription by counterparty) ──
        $lease = Contract::create([
            'kind' => 'lease', 'title' => 'Apartment Lease',
            'starts_on' => now()->subMonths(4)->toDateString(),
            'ends_on' => now()->addMonths(8)->toDateString(),
            'auto_renews' => false, 'renewal_notice_days' => 60,
            'monthly_cost_amount' => 2200, 'monthly_cost_currency' => 'USD',
            'state' => 'active',
        ]);
        $lease->contacts()->attach($landlord->id, ['party_role' => 'counterparty']);

        $phoneContract = Contract::create([
            'kind' => 'subscription', 'title' => 'Verizon Phone',
            'starts_on' => now()->subYear()->toDateString(),
            'auto_renews' => true,
            'monthly_cost_amount' => 60, 'monthly_cost_currency' => 'USD',
            'state' => 'active',
        ]);
        $phoneContract->contacts()->attach($verizon->id, ['party_role' => 'counterparty']);

        $nflx = Contract::create([
            'kind' => 'subscription', 'title' => 'Netflix',
            'starts_on' => now()->subYears(3)->toDateString(),
            'auto_renews' => true,
            'cancellation_url' => 'https://netflix.com/cancel',
            'monthly_cost_amount' => 15.49, 'monthly_cost_currency' => 'USD',
            'state' => 'active',
        ]);
        $nflx->contacts()->attach($netflix->id, ['party_role' => 'counterparty']);

        $gymContract = Contract::create([
            'kind' => 'subscription', 'title' => 'Acme Gym membership',
            'starts_on' => now()->subMonths(11)->toDateString(),
            'ends_on' => now()->addDays(25)->toDateString(),
            'auto_renews' => true, 'renewal_notice_days' => 30,
            'cancellation_email' => 'cancel@acmegym.test',
            'monthly_cost_amount' => 35, 'monthly_cost_currency' => 'USD',
            'state' => 'expiring',
        ]);
        $gymContract->contacts()->attach($gym->id, ['party_role' => 'counterparty']);

        $auto = Contract::create([
            'kind' => 'insurance', 'title' => 'Auto Insurance',
            'starts_on' => now()->subMonths(5)->toDateString(),
            'ends_on' => now()->addMonths(7)->toDateString(),
            'auto_renews' => true, 'renewal_notice_days' => 30,
            'monthly_cost_amount' => 125, 'monthly_cost_currency' => 'USD',
            'state' => 'active',
        ]);
        $auto->contacts()->attach($insurer->id, ['party_role' => 'counterparty']);
        InsurancePolicy::create([
            'contract_id' => $auto->id,
            'coverage_kind' => 'auto',
            'policy_number' => 'SF-44829-A',
            'carrier_contact_id' => $insurer->id,
            'premium_amount' => 125, 'premium_currency' => 'USD', 'premium_cadence' => 'monthly',
            'coverage_amount' => 100000, 'coverage_currency' => 'USD',
            'deductible_amount' => 500, 'deductible_currency' => 'USD',
        ]);

        // ── Documents ────────────────────────────────────────────────────
        Document::create([
            'kind' => 'passport', 'holder_user_id' => $user->id, 'label' => 'US Passport',
            'number' => '*****1234', 'issued_on' => now()->subYears(6)->toDateString(),
            'expires_on' => now()->addDays(75)->toDateString(),
            'in_case_of_pack' => true,
        ]);
        Document::create([
            'kind' => 'license', 'holder_user_id' => $user->id, 'label' => "Driver's license (CA)",
            'number' => '*****A7812', 'issued_on' => now()->subYears(2)->toDateString(),
            'expires_on' => now()->addYears(2)->toDateString(),
        ]);
        Document::create([
            'kind' => 'other', 'label' => 'Car registration',
            'number' => 'REG-98234', 'issued_on' => now()->subMonths(10)->toDateString(),
            'expires_on' => now()->addDays(45)->toDateString(),
        ]);

        // ── Account balance snapshots (feed net-worth sparkline) ─────────
        foreach ([
            [$checking, 5240.18],
            [$savings, 18750.00],
        ] as [$acc, $balance]) {
            AccountBalance::create([
                'account_id' => $acc->id,
                'balance' => $balance, 'currency' => 'USD',
                'as_of' => now()->subMonth()->toDateString(),
            ]);
        }

        // ── Budget caps (BudgetMonitor reads from here) ──────────────────
        foreach ([
            ['food/groceries', 500],
            ['food/dining-out', 300],
            ['transport/fuel', 250],
            ['housing/utilities', 450],
        ] as [$slug, $cap]) {
            if ($id = $this->catId($slug)) {
                BudgetCap::forceCreate([
                    'category_id' => $id,
                    'monthly_cap' => $cap,
                    'currency' => 'USD',
                    'active' => true,
                ]);
            }
        }

        // ── Savings goals ────────────────────────────────────────────────
        SavingsGoal::forceCreate([
            'name' => 'Emergency fund (6 months expenses)',
            'target_amount' => 30000, 'starting_amount' => 10000, 'saved_amount' => 0,
            'account_id' => $savings->id,
            'currency' => 'USD', 'state' => 'active',
            'target_date' => now()->addYear()->toDateString(),
        ]);
        SavingsGoal::forceCreate([
            'name' => 'Japan trip',
            'target_amount' => 5000, 'starting_amount' => 0, 'saved_amount' => 3750,
            'currency' => 'USD', 'state' => 'active',
            'target_date' => now()->addMonths(6)->toDateString(),
        ]);

        // ── Tasks ────────────────────────────────────────────────────────
        $tasks = [
            ['Book dentist checkup', now()->addDays(3), 2, 'open'],
            ['Renew passport', now()->addDays(14), 1, 'open'],
            ['Change HVAC filter', now()->addDays(5), 3, 'open'],
            ['Submit Q2 freelance invoice', now()->subDays(2), 1, 'open'],   // overdue
            ['Buy Mom birthday gift', now(), 2, 'open'],
            ['File receipts for taxes', now()->addDays(20), 4, 'open'],
            ['Winter tires service', now()->subMonths(1), 3, 'done'],
        ];
        foreach ($tasks as [$title, $due, $priority, $state]) {
            Task::create([
                'assigned_user_id' => $user->id, 'title' => $title,
                'due_at' => $due, 'priority' => $priority, 'state' => $state,
                'completed_at' => $state === 'done' ? now()->subDays(25) : null,
            ]);
        }

        // ── Meetings ─────────────────────────────────────────────────────
        foreach ([
            ['1:1 with manager', now()->addDay()->setTime(14, 0), 30, 'Zoom'],
            ['Dentist appointment', now()->addDays(7)->setTime(10, 30), 45, 'SF Dental Group'],
            ['Tax consult with CPA', now()->addDays(10)->setTime(16, 0), 60, 'Phone'],
        ] as [$title, $start, $minutes, $where]) {
            Meeting::create([
                'title' => $title,
                'starts_at' => $start,
                'ends_at' => (clone $start)->addMinutes($minutes),
                'location' => $where,
            ]);
        }

        // ── Projects + time entries ──────────────────────────────────────
        $personal = Project::create([
            'user_id' => $user->id, 'name' => 'Personal', 'slug' => 'personal',
            'color' => '#6366f1', 'billable' => false,
        ]);
        $freelance = Project::create([
            'user_id' => $user->id, 'name' => 'Freelance — Acme', 'slug' => 'freelance-acme',
            'color' => '#10b981', 'billable' => true,
            'hourly_rate' => 150, 'hourly_rate_currency' => 'USD',
            'client_contact_id' => $employer->id,
        ]);
        $learning = Project::create([
            'user_id' => $user->id, 'name' => 'Learning — Rust', 'slug' => 'learning-rust',
            'color' => '#f59e0b', 'billable' => false,
        ]);
        $entries = [
            [1, $freelance, 2.25, 'Client API work'],
            [2, $freelance, 3.50, 'Schema design session'],
            [3, $learning,  1.00, 'Ownership chapter'],
            [4, $freelance, 2.00, 'Code review'],
            [5, $personal,  0.75, 'Inbox triage'],
            [6, $learning,  1.50, 'Traits practice'],
            [7, $freelance, 4.00, 'Performance tuning'],
            [8, $personal,  0.50, 'Bill review'],
            [9, $freelance, 2.75, 'Bug fixes'],
            [10, $learning, 2.00, 'Async chapter'],
            [11, $freelance, 3.25, 'Refactor'],
            [12, $freelance, 1.50, 'Standup + review'],
            [13, $personal, 1.00, 'Reading'],
            [14, $freelance, 2.00, 'Deployment work'],
        ];
        foreach ($entries as [$daysAgo, $project, $hours, $desc]) {
            $seconds = (int) round($hours * 3600);
            $ended = now()->subDays($daysAgo)->setTime(17, 0);
            TimeEntry::create([
                'user_id' => $user->id, 'project_id' => $project->id,
                'started_at' => (clone $ended)->subSeconds($seconds),
                'ended_at' => $ended, 'duration_seconds' => $seconds,
                'activity_date' => $ended->toDateString(),
                'description' => $desc, 'billable' => $project->billable,
            ]);
        }
    }

    /**
     * Invoke every derivation command the app ships. Order matters only
     * slightly — commands are independently idempotent, but running
     * `recurring:project` before `recurring:discover` keeps the latter's
     * noise floor low (known rules are skipped).
     */
    protected function runDerivations(Household $household): void
    {
        $calls = [
            ['recurring:project', ['--household' => $household->id, '--horizon' => 90, '--backfill' => 30]],
            ['categories:apply', []],
            ['subscriptions:backfill', []],
            ['receipts:match', []],
            ['recurring:discover', []],
            ['snapshots:rollup', []],
            ['savings:milestones', []],
        ];
        foreach ($calls as [$cmd, $args]) {
            Artisan::call($cmd, $args);
            $this->command->line("   ran {$cmd}");
        }
    }

    private function catId(string $slug, string $kind = 'expense'): ?int
    {
        return Category::where('kind', $kind)->where('slug', $slug)->value('id');
    }
}

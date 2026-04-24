<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Appointment;
use App\Models\AssetValuation;
use App\Models\BodyMeasurement;
use App\Models\BudgetCap;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Decision;
use App\Models\Document;
use App\Models\Domain;
use App\Models\FoodEntry;
use App\Models\Goal;
use App\Models\HealthProvider;
use App\Models\Household;
use App\Models\InsurancePolicy;
use App\Models\Integration;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\Listing;
use App\Models\Media;
use App\Models\MediaLogEntry;
use App\Models\Meeting;
use App\Models\MeterReading;
use App\Models\OnlineAccount;
use App\Models\Pet;
use App\Models\PetCheckup;
use App\Models\PetLicense;
use App\Models\PetPreventiveCare;
use App\Models\PetVaccination;
use App\Models\Prescription;
use App\Models\Project;
use App\Models\Property;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Reminder;
use App\Models\SavingsGoal;
use App\Models\Tag;
use App\Models\TagRule;
use App\Models\Task;
use App\Models\TaxDocument;
use App\Models\TaxEstimatedPayment;
use App\Models\TaxYear;
use App\Models\TimeEntry;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleServiceLog;
use App\Support\CurrentHousehold;
use App\Support\MediaPlaceholder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Seeds a household with *primary* facts — the ones a real user would type
 * into Secretaire: accounts, contacts, transactions, the rules they set up.
 *
 * Everything *derived* is produced by Secretaire's own engines, not
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

        $primaryDone = Account::exists();

        if (! $primaryDone) {
            $this->command->info('DemoDataSeeder: seeding primary entities…');
            $this->seedPrimary($household, $user);
        } else {
            $this->command->info('DemoDataSeeder: primary data already present — only topping up missing coverage.');
        }

        $this->command->info('DemoDataSeeder: seeding coverage data (pets, logs, assets, radar)…');
        $this->seedCoverage($household, $user);

        $this->command->info('DemoDataSeeder: running derivation pipeline…');
        $this->runDerivations($household);

        // Post-derivation: flip one generated projection to overdue so
        // the bills radar tile fires. Done after recurring:project
        // materialized the rows, since we can't create them ourselves
        // without duplicating the generator's logic.
        $this->lightUpOverdueBill();
    }

    /**
     * Per-section idempotency: skip a coverage block if a signature
     * record is already there. Lets the seeder re-run and fill in
     * just the blocks the user hasn't seen yet, instead of bailing
     * on the first existing row.
     */
    private function skipIf(string $label, bool $already, callable $run): void
    {
        if ($already) {
            $this->command->line("   · {$label} already present");

            return;
        }
        $run();
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

    /**
     * Everything seedPrimary() doesn't touch. Covers the remaining
     * models + lights up every radar tile. Each block mirrors a
     * hub/domain so it's easy to audit what's here.
     */
    protected function seedCoverage(Household $household, User $user): void
    {
        $this->skipIf('Pets', Pet::exists(), fn () => $this->seedPets($user));
        $this->skipIf('Health providers', HealthProvider::where('specialty', 'primary_care')->exists(), fn () => $this->seedHealthExtras());
        $this->skipIf('Checklists', ChecklistTemplate::exists(), fn () => $this->seedChecklists());
        $this->skipIf('Goals', Goal::exists(), fn () => $this->seedGoals());
        $this->skipIf('Logs', JournalEntry::exists(), fn () => $this->seedLogs());
        $this->skipIf('Domains/meters/online accounts', Domain::exists(), fn () => $this->seedDomainsMetersOnlineAccounts());
        $this->skipIf('Inventory + listings', Listing::exists(), fn () => $this->seedInventoryAndListings());
        $this->skipIf('Vehicle services', VehicleServiceLog::exists(), fn () => $this->seedVehicleServices());
        $this->skipIf('Taxes', TaxYear::exists(), fn () => $this->seedTaxes());
        $this->skipIf('Reminders + appointments', Reminder::exists(), fn () => $this->seedRemindersAndAppointments());
        $this->skipIf('Integrations', Integration::exists(), fn () => $this->seedIntegrations());
        $this->skipIf('Trial contract + gift card', Account::where('type', 'gift_card')->exists(), fn () => $this->seedContractsAndAccountsExtras());
        $this->skipIf('Bills inbox media', Media::whereNull('processed_at')->where('ocr_status', 'done')->exists(), fn () => $this->seedBillsInboxMedia());
        $this->closeOneSavingsGoal();

        // Write placeholder bytes for any Media row whose underlying
        // file isn't on disk. Keeps the library picker + thumbnails
        // from 404'ing on seeded rows that point at paths like
        // "seed/bills/pge-nov.pdf" with no actual file.
        $written = MediaPlaceholder::repairAll();
        if ($written > 0) {
            $this->command->line("   · wrote {$written} placeholder media file(s)");
        }
    }

    // ── Pets ───────────────────────────────────────────────────────────────
    protected function seedPets(User $user): void
    {
        $vet = HealthProvider::create([
            'specialty' => 'vet',
            'name' => 'Greenbrook Animal Hospital',
            'notes' => 'Dr. Alvarez — front-desk 9-5 weekdays.',
        ]);

        $rex = Pet::create([
            'species' => 'dog', 'name' => 'Rex', 'breed' => 'Labrador',
            'color' => 'yellow', 'date_of_birth' => now()->subYears(6)->toDateString(),
            'sex' => 'male', 'primary_owner_user_id' => $user->id,
            'vet_provider_id' => $vet->id, 'is_active' => true,
        ]);
        $biscuit = Pet::create([
            'species' => 'dog', 'name' => 'Biscuit', 'breed' => 'Mixed',
            'color' => 'brown', 'date_of_birth' => now()->subYears(3)->toDateString(),
            'sex' => 'female', 'primary_owner_user_id' => $user->id,
            'vet_provider_id' => $vet->id, 'is_active' => true,
        ]);
        $mochi = Pet::create([
            'species' => 'cat', 'name' => 'Mochi', 'breed' => 'Domestic shorthair',
            'color' => 'black', 'date_of_birth' => now()->subYears(4)->toDateString(),
            'sex' => 'female', 'primary_owner_user_id' => $user->id,
            'vet_provider_id' => $vet->id, 'is_active' => true,
        ]);

        // Vaccinations — one expiring soon (radar fires)
        PetVaccination::create([
            'pet_id' => $rex->id, 'vaccine_name' => 'Rabies',
            'administered_on' => now()->subMonths(11)->toDateString(),
            'valid_until' => now()->addDays(12)->toDateString(),
        ]);
        PetVaccination::create([
            'pet_id' => $biscuit->id, 'vaccine_name' => 'DHPP',
            'administered_on' => now()->subYears(2)->toDateString(),
            'valid_until' => now()->subDays(20)->toDateString(), // expired
        ]);
        PetVaccination::create([
            'pet_id' => $mochi->id, 'vaccine_name' => 'FVRCP',
            'administered_on' => now()->subMonths(6)->toDateString(),
            'valid_until' => now()->addMonths(6)->toDateString(),
        ]);

        // Checkups — one overdue (radar fires)
        PetCheckup::create([
            'pet_id' => $rex->id, 'provider_id' => $vet->id,
            'kind' => 'annual_checkup', 'checkup_on' => now()->subMonths(14)->toDateString(),
            'next_due_on' => now()->subDays(30)->toDateString(),
            'cost' => 185.00, 'currency' => 'USD',
        ]);
        PetCheckup::create([
            'pet_id' => $biscuit->id, 'provider_id' => $vet->id,
            'kind' => 'annual_checkup', 'checkup_on' => now()->subMonths(3)->toDateString(),
            'next_due_on' => now()->addMonths(9)->toDateString(),
            'cost' => 195.00, 'currency' => 'USD',
        ]);

        // Licenses — one expiring soon (radar fires)
        PetLicense::create([
            'pet_id' => $rex->id, 'authority' => 'San Mateo County',
            'license_number' => 'SMC-20250412',
            'issued_on' => now()->subMonths(11)->toDateString(),
            'expires_on' => now()->addDays(18)->toDateString(),
            'fee' => 22.00, 'currency' => 'USD',
        ]);

        // Preventive care — one due soon (radar fires)
        PetPreventiveCare::create([
            'pet_id' => $rex->id, 'kind' => 'heartworm', 'label' => 'Heartgard',
            'applied_on' => now()->subDays(26)->toDateString(),
            'interval_days' => 30,
            'next_due_on' => now()->addDays(4)->toDateString(),
            'cost' => 15.00, 'currency' => 'USD',
        ]);
        PetPreventiveCare::create([
            'pet_id' => $biscuit->id, 'kind' => 'flea_tick', 'label' => 'Bravecto',
            'applied_on' => now()->subDays(60)->toDateString(),
            'interval_days' => 90,
            'next_due_on' => now()->addDays(30)->toDateString(),
            'cost' => 65.00, 'currency' => 'USD',
        ]);

        // Prescription — a pet meds example.
        Prescription::create([
            'subject_type' => Pet::class, 'subject_id' => $rex->id,
            'name' => 'Apoquel', 'dosage' => '16 mg / day',
            'active_from' => now()->subMonths(2)->toDateString(),
            'refills_left' => 2,
            'next_refill_on' => now()->addDays(15)->toDateString(),
        ]);
    }

    // ── Health provider for humans ────────────────────────────────────────
    protected function seedHealthExtras(): void
    {
        HealthProvider::create([
            'specialty' => 'primary_care',
            'name' => 'Bay Family Medicine',
            'notes' => 'Dr. Okafor. Portal at bfm.health.',
        ]);
        HealthProvider::create(['specialty' => 'dentist', 'name' => 'Smile Bright Dental']);
    }

    // ── Checklists ────────────────────────────────────────────────────────
    protected function seedChecklists(): void
    {
        $morning = ChecklistTemplate::create([
            'name' => 'Morning ritual',
            'time_of_day' => 'morning',
            'rrule' => 'FREQ=DAILY',
            'dtstart' => now()->subMonth()->toDateString(),
            'active' => true,
            'color' => '#f59e0b',
            'sort_order' => 1,
        ]);
        foreach (['Hydrate', 'Stretch 5 min', 'Feed pets', 'Check calendar'] as $i => $label) {
            ChecklistTemplateItem::create([
                'checklist_template_id' => $morning->id,
                'label' => $label,
                'position' => $i,
                'active' => true,
            ]);
        }

        $evening = ChecklistTemplate::create([
            'name' => 'Evening ritual',
            'time_of_day' => 'evening',
            'rrule' => 'FREQ=DAILY',
            'dtstart' => now()->subMonth()->toDateString(),
            'active' => true,
            'color' => '#6366f1',
            'sort_order' => 2,
        ]);
        foreach (['Dishes', 'Lay out clothes', 'Lights out by 23:00'] as $i => $label) {
            ChecklistTemplateItem::create([
                'checklist_template_id' => $evening->id,
                'label' => $label,
                'position' => $i,
                'active' => true,
            ]);
        }

        // Shopping list — one-off, as a checklist
        $shop = ChecklistTemplate::create([
            'name' => 'Weekend shopping',
            'time_of_day' => 'anytime',
            'rrule' => null,
            'dtstart' => now()->toDateString(),
            'active' => true,
            'sort_order' => 10,
        ]);
        foreach (['Milk', 'Eggs', 'Coffee beans', 'Dog food'] as $i => $label) {
            ChecklistTemplateItem::create([
                'checklist_template_id' => $shop->id,
                'label' => $label,
                'position' => $i,
                'active' => true,
            ]);
        }
    }

    // ── Goals (productivity hub / radar) ──────────────────────────────────
    protected function seedGoals(): void
    {
        // Target mode, behind pace: 100 days elapsed of 200-day target,
        // but only 20/100 progress → elapsed 50%, progress 20% → behind.
        Goal::create([
            'title' => 'Ship v1 of Secretaire',
            'category' => 'work',
            'mode' => 'target',
            'target_value' => 100,
            'current_value' => 20,
            'unit' => '%',
            'started_on' => now()->subDays(100)->toDateString(),
            'target_date' => now()->addDays(100)->toDateString(),
            'status' => 'active',
        ]);

        // Direction mode, stale: 7-day cadence, last reflected 10 days ago.
        Goal::create([
            'title' => 'Keep learning — read one book per month',
            'category' => 'learning',
            'mode' => 'direction',
            'target_value' => 0,
            'current_value' => 0,
            'cadence_days' => 7,
            'last_reflected_at' => now()->subDays(10),
            'started_on' => now()->subMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        // Healthy on-track goal for visual variety.
        Goal::create([
            'title' => 'Run 500 km this year',
            'category' => 'health',
            'mode' => 'target',
            'target_value' => 500,
            'current_value' => 180,
            'unit' => 'km',
            'started_on' => now()->startOfYear()->toDateString(),
            'target_date' => now()->endOfYear()->toDateString(),
            'status' => 'active',
        ]);
    }

    // ── Logs hub (journal, decisions, reading, food, body) ────────────────
    protected function seedLogs(): void
    {
        JournalEntry::create([
            'occurred_on' => now()->subDay()->toDateString(),
            'title' => 'First walk after the storm',
            'body' => 'Took Rex out along the trail. Ground still damp; air felt clean.',
            'mood' => 'calm',
        ]);
        JournalEntry::create([
            'occurred_on' => now()->toDateString(),
            'title' => null,
            'body' => 'Shipping-sprint day. Radar finally shows something useful. Three snoozed, two critical.',
            'mood' => 'focused',
        ]);

        // Decision with follow-up due (radar fires)
        Decision::create([
            'decided_on' => now()->subDays(14)->toDateString(),
            'title' => 'Switch email provider',
            'context' => 'Current provider has kept losing filters.',
            'options_considered' => "Stay with Fastmail\nMigrate to Proton\nRoll own",
            'chosen' => 'Migrate to Proton',
            'rationale' => 'Better filter UX; comparable pricing.',
            'follow_up_on' => now()->subDays(2)->toDateString(), // past → radar fires
            'outcome' => null,
        ]);
        Decision::create([
            'decided_on' => now()->subMonths(2)->toDateString(),
            'title' => 'Adopt Tailwind v4',
            'chosen' => 'Yes — after JIT matures',
            'rationale' => 'Build speed + smaller bundle won.',
            'outcome' => 'Worked well. No regrets.',
        ]);

        MediaLogEntry::create([
            'title' => 'The Design of Everyday Things',
            'kind' => 'book',
            'started_on' => now()->subDays(8)->toDateString(),
            'finished_on' => now()->subDays(1)->toDateString(),
            'rating' => 4,
        ]);
        MediaLogEntry::create([
            'title' => 'The Diplomat — S2',
            'kind' => 'show',
            'started_on' => now()->subDays(3)->toDateString(),
        ]);

        FoodEntry::create([
            'eaten_at' => now()->subHours(6),
            'label' => 'Oatmeal with banana + coffee',
            'kind' => 'breakfast',
        ]);
        FoodEntry::create([
            'eaten_at' => now()->subHours(1),
            'label' => 'Chicken bowl from lunch spot',
            'kind' => 'lunch',
        ]);

        BodyMeasurement::create([
            'measured_at' => now()->startOfDay()->addHours(7),
            'weight_kg' => 75.2,
            'body_fat_pct' => 21.4,
            'muscle_pct' => 41.0,
        ]);
        BodyMeasurement::create([
            'measured_at' => now()->subWeek()->startOfDay()->addHours(7),
            'weight_kg' => 75.8,
            'body_fat_pct' => 21.8,
            'muscle_pct' => 40.6,
        ]);
    }

    // ── Assets: domains, meters, online accounts, inventory, listings ────
    protected function seedDomainsMetersOnlineAccounts(): void
    {
        // Domain expiring soon, not auto-renewing (radar fires)
        Domain::create([
            'name' => 'aurnata.com',
            'registrar' => 'Hover',
            'registered_on' => now()->subYears(2)->toDateString(),
            'expires_on' => now()->addDays(22)->toDateString(),
            'auto_renew' => false,
            'annual_cost' => 12.99, 'currency' => 'USD',
        ]);
        Domain::create([
            'name' => 'secretaire.aurnata.com',
            'registrar' => 'Hover',
            'registered_on' => now()->subYears(1)->toDateString(),
            'expires_on' => now()->addMonths(11)->toDateString(),
            'auto_renew' => true,
        ]);

        $property = Property::first();
        if ($property) {
            foreach (['water', 'electric', 'gas'] as $kind) {
                for ($i = 5; $i >= 0; $i--) {
                    MeterReading::create([
                        'property_id' => $property->id,
                        'kind' => $kind,
                        'read_on' => now()->subMonths($i)->startOfMonth()->toDateString(),
                        'value' => 1000 + ($i * 120) + random_int(-15, 15),
                        'unit' => match ($kind) {
                            'water' => 'gal',
                            'electric' => 'kWh',
                            'gas' => 'therms',
                        },
                    ]);
                }
            }
        }

        OnlineAccount::create([
            'service_name' => 'GitHub',
            'url' => 'https://github.com',
            'login_email' => 'user@example.com',
            'mfa_method' => 'security_key',
            'importance_tier' => 'critical',
            'in_case_of_pack' => true,
        ]);
        OnlineAccount::create([
            'service_name' => 'Fastmail',
            'url' => 'https://fastmail.com',
            'login_email' => 'user@example.com',
            'mfa_method' => 'totp',
            'importance_tier' => 'critical',
            'in_case_of_pack' => true,
        ]);
    }

    protected function seedInventoryAndListings(): void
    {
        // One processed, one unprocessed (radar fires on the latter)
        InventoryItem::create([
            'name' => 'Sony WH-1000XM5 Headphones',
            'category' => 'electronic',
            'room' => 'Office',
            'purchased_on' => now()->subYears(1)->toDateString(),
            'cost_amount' => 399.99, 'cost_currency' => 'USD',
            'brand' => 'Sony', 'model_number' => 'WH-1000XM5',
            'serial_number' => 'ABCD12345',
            'warranty_expires_on' => now()->addYears(1)->toDateString(),
            'processed_at' => now()->subMonth(),
        ]);
        InventoryItem::create([
            'name' => 'Unsorted camera kit',
            'category' => 'electronic',
            'room' => 'Closet',
            'processed_at' => null,
        ]);

        Listing::create([
            'platform' => 'ebay',
            'status' => 'live',
            'title' => 'Vintage lens, used condition',
            'price' => 120, 'currency' => 'USD',
            'external_url' => 'https://ebay.com/itm/0000',
            'posted_on' => now()->subDays(25)->toDateString(),
            'expires_on' => now()->addDays(3)->toDateString(), // radar fires
        ]);
        Listing::create([
            'platform' => 'craigslist',
            'status' => 'live',
            'title' => 'Office chair',
            'price' => 60, 'currency' => 'USD',
            'posted_on' => now()->subDays(5)->toDateString(),
            'expires_on' => now()->addMonths(1)->toDateString(),
        ]);
    }

    protected function seedVehicleServices(): void
    {
        $vehicle = Vehicle::first();
        if (! $vehicle) {
            return;
        }

        // Old oil change with a next-due in the past → radar fires.
        VehicleServiceLog::create([
            'vehicle_id' => $vehicle->id,
            'service_date' => now()->subMonths(7)->toDateString(),
            'kind' => 'oil_change',
            'label' => 'Full synthetic',
            'odometer' => 78000,
            'odometer_unit' => 'mi',
            'cost' => 95.00, 'currency' => 'USD',
            'next_due_on' => now()->subDays(10)->toDateString(),
        ]);
        VehicleServiceLog::create([
            'vehicle_id' => $vehicle->id,
            'service_date' => now()->subMonths(2)->toDateString(),
            'kind' => 'tire_rotation',
            'odometer' => 79500,
            'odometer_unit' => 'mi',
            'cost' => 40.00, 'currency' => 'USD',
            'next_due_on' => now()->addMonths(4)->toDateString(),
        ]);
    }

    protected function seedTaxes(): void
    {
        $currentYear = (int) now()->year;
        $ty = TaxYear::create([
            'year' => $currentYear,
            'jurisdiction' => 'US-federal',
            'state' => 'prep',
        ]);
        // Quarterly estimated payments — Q1/Q2 paid, Q3 unpaid due soon (radar fires)
        foreach ([
            ['Q1', now()->subDays(120)->toDateString(), 1500, now()->subDays(122)->toDateString()],
            ['Q2', now()->subDays(30)->toDateString(), 1500, now()->subDays(32)->toDateString()],
            ['Q3', now()->addDays(20)->toDateString(), 1500, null],
            ['Q4', now()->addMonths(5)->toDateString(), 1500, null],
        ] as [$q, $dueOn, $amt, $paidOn]) {
            TaxEstimatedPayment::create([
                'tax_year_id' => $ty->id,
                'quarter' => $q,
                'due_on' => $dueOn,
                'paid_on' => $paidOn,
                'amount' => $amt,
                'currency' => 'USD',
            ]);
        }

        TaxDocument::create([
            'tax_year_id' => $ty->id,
            'kind' => 'w2',
            'label' => 'W-2 (employer)',
        ]);
    }

    protected function seedRemindersAndAppointments(): void
    {
        // Reminder pending + past remind_at (radar fires)
        Reminder::create([
            'title' => 'Call dad',
            'body' => 'Quick check-in.',
            'remind_at' => now()->subHours(2),
            'state' => 'pending',
            'channel' => 'in_app',
        ]);
        Reminder::create([
            'title' => 'Pay quarterly tax',
            'remind_at' => now()->addDays(18),
            'state' => 'pending',
            'channel' => 'email',
        ]);

        $pet = Pet::first();
        if ($pet) {
            Appointment::create([
                'subject_type' => Pet::class, 'subject_id' => $pet->id,
                'purpose' => 'Annual checkup + boosters',
                'starts_at' => now()->addDays(10)->setTime(14, 0),
                'ends_at' => now()->addDays(10)->setTime(15, 0),
                'location' => 'Greenbrook Animal Hospital',
                'state' => 'scheduled',
            ]);
        }
    }

    protected function seedIntegrations(): void
    {
        // One healthy, one in error (radar fires)
        Integration::create([
            'provider' => 'google_cal',
            'kind' => 'calendar',
            'label' => 'Personal calendar',
            'status' => 'active',
            'last_synced_at' => now()->subHour(),
        ]);
        Integration::create([
            'provider' => 'gmail',
            'kind' => 'mail',
            'label' => 'Personal inbox',
            'status' => 'error',
            'last_synced_at' => now()->subDays(2),
            'last_error' => 'invalid_grant: refresh token revoked.',
        ]);
    }

    protected function seedContractsAndAccountsExtras(): void
    {
        // Trial ending soon (radar fires)
        $svc = Contact::firstOrCreate(['kind' => 'org', 'display_name' => 'Linear']);
        $trial = Contract::create([
            'kind' => 'subscription',
            'title' => 'Linear trial',
            'starts_on' => now()->subDays(7)->toDateString(),
            'trial_ends_on' => now()->addDays(5)->toDateString(),
            'state' => 'active',
            'auto_renews' => true,
            'cancellation_url' => 'https://linear.app/settings/billing',
            'monthly_cost_amount' => 8, 'monthly_cost_currency' => 'USD',
        ]);
        $trial->contacts()->attach($svc->id, ['party_role' => 'counterparty']);

        // Gift card account expiring soon (radar fires)
        Account::create([
            'name' => 'Apple Gift Card',
            'type' => 'gift_card',
            'currency' => 'USD',
            'expires_on' => now()->addDays(25)->toDateString(),
            'is_active' => true,
        ]);
    }

    /**
     * Seeds a Media row styled like a processed bill the OCR pipeline
     * has extracted but the user hasn't reconciled yet — fires the
     * Bills Inbox radar tile.
     */
    protected function seedBillsInboxMedia(): void
    {
        Media::create([
            'disk' => 'local',
            'path' => 'seed/bills/pge-nov.pdf',
            'original_name' => 'PGE-Statement-Nov.pdf',
            'mime' => 'application/pdf',
            'size' => 128_456,
            'ocr_status' => 'done',
            'ocr_extracted' => [
                'merchant' => 'Pacific Gas & Electric',
                'total' => 142.35,
                'currency' => 'USD',
                'issued_on' => now()->subDays(4)->toDateString(),
            ],
            'processed_at' => null,
        ]);
    }

    /**
     * Flips an existing active savings goal to 100% so the
     * "savings goals ready to close" radar tile fires.
     */
    protected function closeOneSavingsGoal(): void
    {
        $g = SavingsGoal::where('state', 'active')->orderBy('id')->first();
        if (! $g) {
            return;
        }
        $target = (float) $g->target_amount;
        if ($target <= 0) {
            return;
        }
        // saved_amount is the "how much sits in the jar" field; hitting
        // target makes the radar "ready to close" tile fire.
        $g->update(['saved_amount' => $target]);
    }

    /**
     * Promote one generated projection to overdue so the Overdue Bills
     * radar tile lights up. Runs after recurring:project.
     */
    protected function lightUpOverdueBill(): void
    {
        $p = RecurringProjection::query()
            ->where('autopay', false)
            ->orderBy('due_on')
            ->first();
        if (! $p) {
            return;
        }
        $p->update([
            'status' => 'overdue',
            'due_on' => now()->subDays(3)->toDateString(),
        ]);
    }
}

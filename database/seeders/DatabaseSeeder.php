<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AssetValuation;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Household;
use App\Models\InsurancePolicy;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\Property;
use App\Models\RecurringRule;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\CurrentHousehold;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $household = Household::firstOrCreate(
                ['name' => 'Bureau'],
                ['default_currency' => 'USD']
            );

            $user = User::firstOrCreate(
                ['email' => 'boris@gurvich.me'],
                [
                    'name' => 'Boris Gurvich',
                    'password' => Hash::make('change-me'),
                    'default_household_id' => $household->id,
                ]
            );

            if ($user->default_household_id !== $household->id) {
                $user->forceFill(['default_household_id' => $household->id])->save();
            }

            $household->users()->syncWithoutDetaching([
                $user->id => ['role' => 'owner', 'joined_at' => now()],
            ]);

            CurrentHousehold::set($household);

            $this->seedCategories($household->id);
            $this->call(SystemCategoriesSeeder::class);
            $this->seedDemoData($household, $user);
        });
    }

    protected function seedCategories(int $householdId): void
    {
        $expenses = [
            'Housing' => ['Rent / Mortgage', 'Utilities', 'Maintenance', 'Furnishings'],
            'Transport' => ['Fuel', 'Public Transit', 'Parking', 'Tolls', 'Vehicle Service'],
            'Food' => ['Groceries', 'Dining Out', 'Coffee'],
            'Health' => ['Medical', 'Dental', 'Vision', 'Pharmacy', 'Fitness'],
            'Insurance' => ['Auto', 'Home', 'Health', 'Life'],
            'Subscriptions' => ['Software', 'Media', 'News'],
            'Personal' => ['Clothing', 'Grooming', 'Hobbies'],
            'Travel' => ['Flights', 'Lodging', 'Activities'],
            'Kids' => ['School', 'Activities', 'Clothing'],
            'Pets' => ['Food', 'Vet', 'Grooming', 'Supplies'],
            'Taxes' => ['Federal', 'State', 'Property', 'Other'],
            'Gifts & Donations' => [],
            'Fees & Bank' => [],
            'Other' => [],
        ];

        $income = [
            'Salary' => [],
            'Freelance' => [],
            'Investments' => ['Dividends', 'Interest', 'Capital Gains'],
            'Rental' => [],
            'Gifts Received' => [],
            'Refunds' => [],
            'Other Income' => [],
        ];

        foreach ($expenses as $parent => $children) {
            $parentCat = Category::firstOrCreate(
                ['household_id' => $householdId, 'kind' => 'expense', 'slug' => Str::slug($parent)],
                ['name' => $parent]
            );
            foreach ($children as $child) {
                Category::firstOrCreate(
                    ['household_id' => $householdId, 'kind' => 'expense', 'slug' => Str::slug($parent).'/'.Str::slug($child)],
                    ['name' => $child, 'parent_id' => $parentCat->id]
                );
            }
        }

        foreach ($income as $parent => $children) {
            $parentCat = Category::firstOrCreate(
                ['household_id' => $householdId, 'kind' => 'income', 'slug' => Str::slug($parent)],
                ['name' => $parent]
            );
            foreach ($children as $child) {
                Category::firstOrCreate(
                    ['household_id' => $householdId, 'kind' => 'income', 'slug' => Str::slug($parent).'/'.Str::slug($child)],
                    ['name' => $child, 'parent_id' => $parentCat->id]
                );
            }
        }

        Category::firstOrCreate(
            ['household_id' => $householdId, 'kind' => 'transfer', 'slug' => 'transfer'],
            ['name' => 'Transfer']
        );
    }

    protected function seedDemoData(Household $household, User $user): void
    {
        if (Account::exists()) {
            return; // idempotent: only seed demo data on a fresh DB
        }

        $cat = fn (string $slug, string $kind = 'expense') => Category::where('kind', $kind)->where('slug', $slug)->value('id');

        // ── Contacts ─────────────────────────────────────────────────────────
        $landlord = Contact::create(['kind' => 'org', 'display_name' => 'Bay View Properties']);
        $verizon = Contact::create(['kind' => 'org', 'display_name' => 'Verizon']);
        $netflix = Contact::create(['kind' => 'org', 'display_name' => 'Netflix']);
        $gym = Contact::create(['kind' => 'org', 'display_name' => 'Acme Gym']);
        $insurer = Contact::create(['kind' => 'org', 'display_name' => 'State Farm']);
        $employer = Contact::create(['kind' => 'org', 'display_name' => 'Acme Corp']);
        Contact::create(['kind' => 'person', 'display_name' => 'Dr. Sarah Chen', 'favorite' => true]);
        Contact::create(['kind' => 'person', 'display_name' => 'Mom']);

        // ── Accounts ─────────────────────────────────────────────────────────
        $checking = Account::create([
            'type' => 'bank', 'name' => 'Chase Checking', 'institution' => 'Chase',
            'currency' => 'USD', 'opening_balance' => 5240.18,
        ]);
        $savings = Account::create([
            'type' => 'bank', 'name' => 'Chase Savings', 'institution' => 'Chase',
            'currency' => 'USD', 'opening_balance' => 18750.00,
        ]);
        $amex = Account::create([
            'type' => 'credit', 'name' => 'Amex Gold', 'institution' => 'American Express',
            'currency' => 'USD', 'opening_balance' => -842.30,
        ]);
        $brokerage = Account::create([
            'type' => 'investment', 'name' => 'Vanguard Brokerage', 'institution' => 'Vanguard',
            'currency' => 'USD', 'opening_balance' => 72430.55,
        ]);

        // ── Transactions (last 60 days) ──────────────────────────────────────
        $txns = [
            // rent
            [28, $checking, -2200, 'Rent payment', $cat('housing/rent-mortgage'), $landlord->id],
            // salary
            [15, $checking, 4850, 'Acme Corp payroll', $cat('salary', 'income'), $employer->id],
            [1,  $checking, 4850, 'Acme Corp payroll', $cat('salary', 'income'), $employer->id],
            // subscriptions
            [12, $amex,     -15.49, 'Netflix', $cat('subscriptions/media'), $netflix->id],
            [5,  $amex,     -60.00, 'Verizon Wireless', $cat('housing/utilities'), $verizon->id],
            [3,  $amex,     -35.00, 'Acme Gym', $cat('health/fitness'), $gym->id],
            // groceries
            [2,  $amex,     -84.12, 'Whole Foods', $cat('food/groceries'), null],
            [9,  $amex,     -112.44, 'Trader Joe\'s', $cat('food/groceries'), null],
            [16, $amex,     -76.80, 'Safeway', $cat('food/groceries'), null],
            [23, $amex,     -98.50, 'Whole Foods', $cat('food/groceries'), null],
            [30, $amex,     -64.11, 'Costco', $cat('food/groceries'), null],
            // dining
            [6,  $amex,     -42.80, 'Sushi Ran', $cat('food/dining-out'), null],
            [13, $amex,     -18.50, 'Blue Bottle Coffee', $cat('food/coffee'), null],
            [20, $amex,     -95.20, 'Izakaya', $cat('food/dining-out'), null],
            [27, $amex,     -22.00, 'Thai lunch', $cat('food/dining-out'), null],
            // transport
            [4,  $amex,     -52.30, 'Shell gas', $cat('transport/fuel'), null],
            [18, $amex,     -48.75, 'Shell gas', $cat('transport/fuel'), null],
            [10, $amex,     -12.50, 'Parking', $cat('transport/parking'), null],
            // utilities / other bills
            [11, $checking, -135.40, 'PG&E electric', $cat('housing/utilities'), null],
            [11, $checking, -68.20, 'SFPUC water', $cat('housing/utilities'), null],
            // health
            [14, $amex,     -320.00, 'Dental cleaning', $cat('health/dental'), null],
            // personal / misc
            [8,  $amex,     -58.99, 'REI — jacket', $cat('personal/clothing'), null],
            [22, $amex,     -29.99, 'GitHub Pro', $cat('subscriptions/software'), null],
            // refund / misc income
            [7,  $checking, 45.00, 'Amazon refund', $cat('refunds', 'income'), null],
            // amex autopay
            [25, $checking, -1420.50, 'Amex autopay', null, null],
            [25, $amex,      1420.50, 'Amex autopay', null, null],
        ];

        foreach ($txns as [$daysAgo, $acct, $amount, $desc, $categoryId, $cpId]) {
            Transaction::create([
                'account_id' => $acct->id,
                'category_id' => $categoryId,
                'counterparty_contact_id' => $cpId,
                'occurred_on' => now()->subDays($daysAgo)->toDateString(),
                'amount' => $amount,
                'currency' => 'USD',
                'description' => $desc,
                'status' => 'cleared',
            ]);
        }

        // one pending (unreconciled) to light up the attention radar
        Transaction::create([
            'account_id' => $amex->id,
            'occurred_on' => now()->subDays(1)->toDateString(),
            'amount' => -128.40,
            'currency' => 'USD',
            'description' => 'Flowers (pending)',
            'status' => 'pending',
        ]);

        // ── Transfers ────────────────────────────────────────────────────────
        Transfer::create([
            'occurred_on' => now()->subDays(15)->toDateString(),
            'from_account_id' => $checking->id,
            'from_amount' => 1000, 'from_currency' => 'USD',
            'to_account_id' => $savings->id,
            'to_amount' => 1000, 'to_currency' => 'USD',
            'description' => 'Monthly savings',
            'status' => 'cleared',
        ]);

        // ── Contracts + one insurance ────────────────────────────────────────
        $lease = Contract::create([
            'kind' => 'lease', 'title' => 'Apartment Lease',
            'starts_on' => now()->subMonths(4)->toDateString(),
            'ends_on' => now()->addMonths(8)->toDateString(),
            'auto_renews' => false, 'renewal_notice_days' => 60,
            'monthly_cost_amount' => 2200, 'monthly_cost_currency' => 'USD',
            'state' => 'active',
        ]);
        $lease->contacts()->attach($landlord->id, ['party_role' => 'counterparty']);

        $phone = Contract::create([
            'kind' => 'subscription', 'title' => 'Verizon Phone',
            'starts_on' => now()->subYear()->toDateString(),
            'auto_renews' => true,
            'monthly_cost_amount' => 60, 'monthly_cost_currency' => 'USD',
            'state' => 'active',
        ]);
        $phone->contacts()->attach($verizon->id, ['party_role' => 'counterparty']);

        $nflx = Contract::create([
            'kind' => 'subscription', 'title' => 'Netflix',
            'starts_on' => now()->subYears(3)->toDateString(),
            'auto_renews' => true,
            'monthly_cost_amount' => 15.49, 'monthly_cost_currency' => 'USD',
            'state' => 'active',
        ]);
        $nflx->contacts()->attach($netflix->id, ['party_role' => 'counterparty']);

        $gymContract = Contract::create([
            'kind' => 'subscription', 'title' => 'Acme Gym membership',
            'starts_on' => now()->subMonths(11)->toDateString(),
            'ends_on' => now()->addDays(25)->toDateString(),
            'auto_renews' => true, 'renewal_notice_days' => 30,
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

        // ── Property / vehicle / valuations ──────────────────────────────────
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

        // ── Documents (with expiries to light up radars) ─────────────────────
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

        // ── Tasks ────────────────────────────────────────────────────────────
        $tasks = [
            ['Book dentist checkup', now()->addDays(3), 2, 'open'],
            ['Renew passport (starts 6 mo before expiry)', now()->addDays(14), 1, 'open'],
            ['Change HVAC filter', now()->addDays(5), 3, 'open'],
            ['Submit Q2 freelance invoice', now()->subDays(2), 1, 'open'], // overdue
            ['Buy Mom birthday gift', now(), 2, 'open'],
            ['File receipts for taxes', now()->addDays(20), 4, 'open'],
            ['Winter tires service', now()->subMonths(1), 3, 'done'],
        ];
        foreach ($tasks as [$title, $due, $priority, $state]) {
            Task::create([
                'assigned_user_id' => $user->id,
                'title' => $title,
                'due_at' => $due,
                'priority' => $priority,
                'state' => $state,
                'completed_at' => $state === 'done' ? now()->subDays(25) : null,
            ]);
        }

        // ── Meetings ─────────────────────────────────────────────────────────
        Meeting::create([
            'title' => '1:1 with manager',
            'starts_at' => now()->addDay()->setTime(14, 0),
            'ends_at' => now()->addDay()->setTime(14, 30),
            'location' => 'Zoom',
        ]);
        Meeting::create([
            'title' => 'Dentist appointment',
            'starts_at' => now()->addDays(7)->setTime(10, 30),
            'ends_at' => now()->addDays(7)->setTime(11, 15),
            'location' => 'SF Dental Group',
        ]);
        Meeting::create([
            'title' => 'Tax consult with CPA',
            'starts_at' => now()->addDays(10)->setTime(16, 0),
            'ends_at' => now()->addDays(10)->setTime(17, 0),
            'location' => 'Phone',
        ]);

        // ── Recurring rules + projections (bill/income horizon) ──────────────
        RecurringRule::create([
            'kind' => 'bill', 'title' => 'Rent',
            'amount' => -2200, 'currency' => 'USD',
            'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
            'dtstart' => now()->subYear()->startOfMonth()->toDateString(),
            'account_id' => $checking->id,
            'counterparty_contact_id' => $landlord->id,
            'category_id' => $cat('housing/rent-mortgage'),
            'lead_days' => 5,
        ]);
        RecurringRule::create([
            'kind' => 'bill', 'title' => 'Netflix',
            'amount' => -15.49, 'currency' => 'USD',
            'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=12',
            'dtstart' => now()->subYears(3)->toDateString(),
            'account_id' => $amex->id,
            'category_id' => $cat('subscriptions/media'),
        ]);
        RecurringRule::create([
            'kind' => 'income', 'title' => 'Acme Corp payroll',
            'amount' => 4850, 'currency' => 'USD',
            'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1,15',
            'dtstart' => now()->subYear()->toDateString(),
            'account_id' => $checking->id,
            'counterparty_contact_id' => $employer->id,
            'category_id' => $cat('salary', 'income'),
        ]);
        RecurringRule::create([
            'kind' => 'maintenance', 'title' => 'Change HVAC filter',
            'rrule' => 'FREQ=MONTHLY;INTERVAL=3',
            'dtstart' => now()->subMonths(2)->toDateString(),
            'subject_type' => Property::class,
            'subject_id' => $home->id,
            'lead_days' => 7,
        ]);

        // materialize projections via the real RRULE generator so demo data
        // matches what the scheduled command produces in production.
        Artisan::call('recurring:project', [
            '--household' => $household->id,
            '--horizon' => 90,
            '--backfill' => 30,
        ]);

        // ── Projects + time entries ──────────────────────────────────────────
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

        // historical entries over last 14 days
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
                'user_id' => $user->id,
                'project_id' => $project->id,
                'started_at' => (clone $ended)->subSeconds($seconds),
                'ended_at' => $ended,
                'duration_seconds' => $seconds,
                'activity_date' => $ended->toDateString(),
                'description' => $desc,
                'billable' => $project->billable,
            ]);
        }
    }
}

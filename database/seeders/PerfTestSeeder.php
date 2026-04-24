<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Household;
use App\Models\JournalEntry;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Volume-only seeder for observing query + render performance on a
 * lived-in data set. Generates ~10k transactions, ~2k tasks, ~5k
 * time entries, ~600 journal entries spread across ~3 years.
 *
 * Explicitly opt-in — run with:
 *   php artisan db:seed --class=PerfTestSeeder
 *
 * Skips (no-op) if there are already >2k transactions so repeated
 * invocations don't compound. To regenerate: truncate affected
 * tables manually, or wipe the DB with the user's confirmation.
 *
 * Inserts in batches via DB::table()->insert([...]) for speed;
 * bypasses model observers by design. The DemoDataSeeder is the
 * right place for observer-driven workflows — this seeder is about
 * volume first.
 */
class PerfTestSeeder extends Seeder
{
    private const TRANSACTIONS_TARGET = 10_000;

    private const TASKS_TARGET = 2_000;

    private const TIME_ENTRIES_TARGET = 5_000;

    private const JOURNAL_ENTRIES_TARGET = 600;

    private const BATCH = 500;

    public function run(): void
    {
        $household = CurrentHousehold::get() ?? Household::query()->orderBy('id')->first();
        if (! $household) {
            $this->command->warn('PerfTestSeeder: no household — run DemoDataSeeder first.');

            return;
        }
        CurrentHousehold::set($household);

        $user = $household->users()->orderBy('users.id')->first() ?? User::query()->orderBy('id')->first();
        if (! $user) {
            $this->command->warn('PerfTestSeeder: no user — run DemoDataSeeder first.');

            return;
        }

        // Fast idempotency: bail early if the volume targets are already
        // met. Caller can truncate if they want to regenerate.
        $current = Transaction::count();
        if ($current >= 2_000) {
            $this->command->info("PerfTestSeeder: already at volume ({$current} transactions) — skipping.");

            return;
        }

        $this->command->info('PerfTestSeeder: generating volume…');

        $this->seedTransactions($household, $user);
        $this->seedTasks($household, $user);
        $this->seedTimeEntries($household, $user);
        $this->seedJournalEntries($household, $user);

        $this->command->info('PerfTestSeeder: done.');
        $this->command->line('   · transactions: '.Transaction::count());
        $this->command->line('   · tasks: '.Task::count());
        $this->command->line('   · time entries: '.TimeEntry::count());
        $this->command->line('   · journal entries: '.JournalEntry::count());
    }

    protected function seedTransactions(Household $household, User $user): void
    {
        $accounts = Account::where('is_active', true)->pluck('id')->all();
        if ($accounts === []) {
            return;
        }
        $categories = Category::where('kind', 'expense')->pluck('id')->all();
        $merchants = Contact::pluck('id')->all();
        if ($merchants === []) {
            return;
        }

        $start = CarbonImmutable::now()->subYears(3);
        $days = CarbonImmutable::now()->diffInDays($start);

        $rows = [];
        for ($i = 0; $i < self::TRANSACTIONS_TARGET; $i++) {
            $dayOffset = random_int(0, (int) $days);
            $occurred = $start->addDays($dayOffset);
            $amt = random_int(-20_000, 8_000) / 100; // mostly outflows
            $rows[] = [
                'household_id' => $household->id,
                'account_id' => $accounts[array_rand($accounts)],
                'occurred_on' => $occurred->toDateString(),
                'amount' => $amt,
                'currency' => 'USD',
                'description' => 'Perf txn #'.$i,
                'counterparty_contact_id' => $merchants[array_rand($merchants)],
                'category_id' => $categories !== [] ? $categories[array_rand($categories)] : null,
                'status' => 'cleared',
                'created_at' => $occurred,
                'updated_at' => $occurred,
            ];
            if (count($rows) >= self::BATCH) {
                DB::table('transactions')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('transactions')->insert($rows);
        }
    }

    protected function seedTasks(Household $household, User $user): void
    {
        $start = CarbonImmutable::now()->subYears(2);
        $days = CarbonImmutable::now()->diffInDays($start);

        $rows = [];
        for ($i = 0; $i < self::TASKS_TARGET; $i++) {
            $created = $start->addDays(random_int(0, (int) $days));
            $state = ['open', 'open', 'open', 'done', 'done', 'waiting', 'dropped'][random_int(0, 6)];
            $due = random_int(0, 1)
                ? $created->addDays(random_int(-30, 120))->toDateTimeString()
                : null;
            $rows[] = [
                'household_id' => $household->id,
                'assigned_user_id' => $user->id,
                'title' => 'Perf task #'.$i,
                'priority' => random_int(1, 5),
                'state' => $state,
                'due_at' => $due,
                'completed_at' => $state === 'done' ? $created->addDays(random_int(0, 30))->toDateTimeString() : null,
                'position' => 0,
                'created_at' => $created,
                'updated_at' => $created,
            ];
            if (count($rows) >= self::BATCH) {
                DB::table('tasks')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('tasks')->insert($rows);
        }
    }

    protected function seedTimeEntries(Household $household, User $user): void
    {
        $start = CarbonImmutable::now()->subYears(2);
        $days = CarbonImmutable::now()->diffInDays($start);

        $rows = [];
        for ($i = 0; $i < self::TIME_ENTRIES_TARGET; $i++) {
            $day = $start->addDays(random_int(0, (int) $days));
            $startedAt = $day->setTime(random_int(8, 18), 0);
            $seconds = random_int(15 * 60, 4 * 3600);
            $rows[] = [
                'household_id' => $household->id,
                'user_id' => $user->id,
                'started_at' => $startedAt->toDateTimeString(),
                'activity_date' => $day->toDateString(),
                'duration_seconds' => $seconds,
                'description' => 'Perf time entry #'.$i,
                'billable' => random_int(0, 1) === 1,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ];
            if (count($rows) >= self::BATCH) {
                DB::table('time_entries')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('time_entries')->insert($rows);
        }
    }

    protected function seedJournalEntries(Household $household, User $user): void
    {
        $start = CarbonImmutable::now()->subYears(2);
        $days = CarbonImmutable::now()->diffInDays($start);

        $moods = ['calm', 'focused', 'tired', 'happy', 'anxious', 'grateful'];
        $rows = [];
        for ($i = 0; $i < self::JOURNAL_ENTRIES_TARGET; $i++) {
            $day = $start->addDays(random_int(0, (int) $days));
            $rows[] = [
                'household_id' => $household->id,
                'user_id' => $user->id,
                'occurred_on' => $day->toDateString(),
                'title' => random_int(0, 1) ? 'Perf journal #'.$i : null,
                'body' => 'Lorem ipsum placeholder body for perf seeder entry '.$i.'. '
                    .str_repeat('The quick brown fox jumps over the lazy dog. ', random_int(1, 5)),
                'mood' => $moods[array_rand($moods)],
                'private' => true,
                'created_at' => $day,
                'updated_at' => $day,
            ];
            if (count($rows) >= self::BATCH) {
                DB::table('journal_entries')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('journal_entries')->insert($rows);
        }
    }
}

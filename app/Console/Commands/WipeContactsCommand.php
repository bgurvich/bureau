<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Household;
use App\Support\ContactMerge;
use App\Support\CurrentHousehold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete every Contact for a household, detaching existing references
 * first so FK constraints don't block the delete. Intended as a
 * companion to transactions:wipe — when iterating on vendor
 * auto-detect / match_patterns / contact merge, sometimes it's
 * simpler to start from a clean contact book and re-import.
 *
 * What happens per contact:
 *   - single-FK columns on referencing tables (transactions,
 *     recurring_rules, accounts.vendor_contact_id, insurance
 *     carriers/brokers, etc.) are nulled, so the referencing records
 *     live on without a counterparty.
 *   - pivot rows (contact_contract, meeting_contact) are deleted.
 *   - polymorphic morph rows (taggables, mediables, task_subjects,
 *     note_subjects, transaction_subjects with subject_type=Contact)
 *     are deleted.
 *   - the contacts themselves are deleted.
 *
 * The schema graph is read from ContactMerge so this command can't
 * drift out of sync with the merge logic — add a new referencing
 * table to ContactMerge and contacts:wipe handles it automatically.
 */
class WipeContactsCommand extends Command
{
    protected $signature = 'contacts:wipe
        {--household= : Household id; defaults to every household}
        {--dry-run : Print the counts and exit without deleting}
        {--force : Skip the interactive confirmation}';

    protected $description = 'Delete every Contact, detaching all references first. Accounts, rules, transactions, etc. stay — their counterparty columns go null.';

    public function handle(): int
    {
        $households = Household::query()
            ->when($this->option('household'), fn ($q) => $q->whereKey($this->option('household')))
            ->get();

        if ($households->isEmpty()) {
            $this->warn('No households matched — nothing to do.');

            return self::SUCCESS;
        }

        foreach ($households as $household) {
            CurrentHousehold::set($household);
            $contactIds = Contact::query()
                ->where('household_id', $household->id)
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            $this->line('');
            $this->line("<comment>[{$household->name}] household #{$household->id}</comment>");
            if ($contactIds === []) {
                $this->info('  No contacts to wipe.');

                continue;
            }

            $counts = $this->counts($contactIds);
            $this->table(
                ['table', 'rows affected'],
                collect($counts)->map(fn (int $n, string $k) => [$k, number_format($n)])->values()->all(),
            );

            if ($this->option('dry-run')) {
                continue;
            }

            $n = count($contactIds);
            if (! $this->option('force') && ! $this->confirm("Delete {$n} contact(s) and detach every reference above for household #{$household->id}?")) {
                $this->warn('Skipped.');

                continue;
            }

            $this->wipeAll($contactIds);
            $this->info('  Wiped.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $contactIds
     * @return array<string, int>
     */
    private function counts(array $contactIds): array
    {
        $counts = ['contacts' => count($contactIds)];

        foreach (ContactMerge::singleFkRefs() as [$table, $column]) {
            $counts["{$table}.{$column} (nulled)"] = (int) DB::table($table)
                ->whereIn($column, $contactIds)
                ->count();
        }
        foreach (ContactMerge::pivotRefs() as [$table, $contactColumn]) {
            $counts["{$table} (pivot)"] = (int) DB::table($table)
                ->whereIn($contactColumn, $contactIds)
                ->count();
        }
        foreach (ContactMerge::morphRefs() as [$table, $typeColumn, $idColumn]) {
            $counts["{$table} (morph Contact)"] = (int) DB::table($table)
                ->where($typeColumn, Contact::class)
                ->whereIn($idColumn, $contactIds)
                ->count();
        }

        return $counts;
    }

    /** @param  array<int, int>  $contactIds */
    private function wipeAll(array $contactIds): void
    {
        DB::transaction(function () use ($contactIds) {
            foreach (ContactMerge::singleFkRefs() as [$table, $column]) {
                DB::table($table)
                    ->whereIn($column, $contactIds)
                    ->update([$column => null]);
            }
            foreach (ContactMerge::pivotRefs() as [$table, $contactColumn]) {
                DB::table($table)
                    ->whereIn($contactColumn, $contactIds)
                    ->delete();
            }
            foreach (ContactMerge::morphRefs() as [$table, $typeColumn, $idColumn]) {
                DB::table($table)
                    ->where($typeColumn, Contact::class)
                    ->whereIn($idColumn, $contactIds)
                    ->delete();
            }
            Contact::whereIn('id', $contactIds)->delete();
        });
    }
}

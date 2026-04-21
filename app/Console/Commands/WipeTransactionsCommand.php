<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Household;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Support\CurrentHousehold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reset the transactional ledger for a household while preserving its
 * configuration. Intended for iterating on statement imports / vendor
 * rules / category rules — delete the dirty data, re-import, re-test.
 *
 * What gets deleted:
 *   - transactions
 *   - transfers
 *   - recurring_projections (materialized recurring rows)
 *   - account_balances (daily balance snapshots; derived)
 *   - snapshots (polymorphic; net-worth history is derived)
 *   - transaction_subjects (polymorphic pivot)
 *   - taggables rows pointing at Transaction or Transfer
 *   - mediables rows pointing at Transaction or Transfer
 *
 * What stays (config and first-class records):
 *   - households / users / household_user
 *   - accounts (and opening balances)
 *   - contacts (including match_patterns)
 *   - categories / category_rules / tag_rules
 *   - recurring_rules (the bills/subscriptions you configured)
 *   - tags (names)
 *   - documents / media / properties / vehicles / inventory / etc.
 *   - checklists + their runs (independent)
 */
class WipeTransactionsCommand extends Command
{
    protected $signature = 'transactions:wipe
        {--household= : Household id; defaults to every household}
        {--dry-run : Print the counts and exit without deleting}
        {--force : Skip the interactive confirmation}';

    protected $description = 'Delete transactions + transfers + derived ledger state. Accounts, rules, contacts, and everything else stay.';

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
            $counts = $this->counts($household->id);

            $this->line('');
            $this->line("<comment>[{$household->name}] household #{$household->id}</comment>");
            $this->table(
                ['table', 'rows'],
                collect($counts)->map(fn (int $n, string $k) => [$k, number_format($n)])->values()->all(),
            );

            if ($this->option('dry-run')) {
                continue;
            }

            if (! $this->option('force') && ! $this->confirm("Delete every row above for household #{$household->id}? Accounts + contacts + rules stay.")) {
                $this->warn('Skipped.');

                continue;
            }

            $this->deleteAll($household->id);
            $this->info('  Wiped.');
        }

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function counts(int $householdId): array
    {
        $txnIds = Transaction::query()->where('household_id', $householdId)->pluck('id');
        $xferIds = Transfer::query()->where('household_id', $householdId)->pluck('id');

        return [
            'transactions' => (int) $txnIds->count(),
            'transfers' => (int) $xferIds->count(),
            'recurring_projections' => (int) DB::table('recurring_projections')
                ->whereIn('rule_id', DB::table('recurring_rules')->where('household_id', $householdId)->select('id'))
                ->count(),
            'account_balances' => (int) DB::table('account_balances')
                ->whereIn('account_id', DB::table('accounts')->where('household_id', $householdId)->select('id'))
                ->count(),
            'snapshots' => (int) DB::table('snapshots')
                ->where('household_id', $householdId)
                ->count(),
            'transaction_subjects (txn)' => (int) DB::table('transaction_subjects')
                ->whereIn('transaction_id', $txnIds)
                ->count(),
            'taggables (txn+transfer)' => (int) DB::table('taggables')
                ->where(function ($q) use ($txnIds, $xferIds) {
                    $q->where(function ($w) use ($txnIds) {
                        $w->where('taggable_type', Transaction::class)->whereIn('taggable_id', $txnIds);
                    })->orWhere(function ($w) use ($xferIds) {
                        $w->where('taggable_type', Transfer::class)->whereIn('taggable_id', $xferIds);
                    });
                })
                ->count(),
            'mediables (txn+transfer)' => (int) DB::table('mediables')
                ->where(function ($q) use ($txnIds, $xferIds) {
                    $q->where(function ($w) use ($txnIds) {
                        $w->where('mediable_type', Transaction::class)->whereIn('mediable_id', $txnIds);
                    })->orWhere(function ($w) use ($xferIds) {
                        $w->where('mediable_type', Transfer::class)->whereIn('mediable_id', $xferIds);
                    });
                })
                ->count(),
        ];
    }

    private function deleteAll(int $householdId): void
    {
        DB::transaction(function () use ($householdId) {
            $txnIds = Transaction::query()->where('household_id', $householdId)->pluck('id');
            $xferIds = Transfer::query()->where('household_id', $householdId)->pluck('id');

            // Clear polymorphic pivots FIRST so FK-less rows don't get
            // orphaned. Laravel's cascade on the FK side handles the
            // real foreign keys, but mediables/taggables are ids-only.
            DB::table('transaction_subjects')->whereIn('transaction_id', $txnIds)->delete();
            DB::table('taggables')
                ->where(function ($q) use ($txnIds, $xferIds) {
                    $q->where(function ($w) use ($txnIds) {
                        $w->where('taggable_type', Transaction::class)->whereIn('taggable_id', $txnIds);
                    })->orWhere(function ($w) use ($xferIds) {
                        $w->where('taggable_type', Transfer::class)->whereIn('taggable_id', $xferIds);
                    });
                })->delete();
            DB::table('mediables')
                ->where(function ($q) use ($txnIds, $xferIds) {
                    $q->where(function ($w) use ($txnIds) {
                        $w->where('mediable_type', Transaction::class)->whereIn('mediable_id', $txnIds);
                    })->orWhere(function ($w) use ($xferIds) {
                        $w->where('mediable_type', Transfer::class)->whereIn('mediable_id', $xferIds);
                    });
                })->delete();

            // Projections tied to rules in this household. Delete
            // rather than just unlink — matched_at fields reference
            // transactions that are about to go, and the user re-
            // generates projections via artisan recurring:project.
            DB::table('recurring_projections')
                ->whereIn('rule_id', DB::table('recurring_rules')->where('household_id', $householdId)->select('id'))
                ->delete();

            // Derived tables.
            DB::table('account_balances')
                ->whereIn('account_id', DB::table('accounts')->where('household_id', $householdId)->select('id'))
                ->delete();
            DB::table('snapshots')->where('household_id', $householdId)->delete();

            // Finally the ledger itself. Transfers reference
            // transactions via from_transaction_id / to_transaction_id
            // — delete transfers first so the FK ON DELETE doesn't
            // surprise-cascade.
            DB::table('transfers')->whereIn('id', $xferIds)->delete();
            DB::table('transactions')->whereIn('id', $txnIds)->delete();
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\Transfer;
use Illuminate\Support\Collection;

/**
 * Balance rollup for a set of Accounts, combining opening_balance + the
 * three per-account sums (cleared transactions, outbound transfers,
 * inbound transfers) in ONE query per table — avoids the per-account
 * loop that used to dominate finance-overview query count.
 */
final class AccountBalances
{
    /**
     * Compute current balance for each of the supplied accounts.
     *
     * @param  Collection<int, Account>|array<int, Account>  $accounts
     * @return array<int, float> accountId → balance (rounded to 2dp)
     */
    public static function forAccounts(Collection|array $accounts): array
    {
        $accounts = $accounts instanceof Collection ? $accounts : collect($accounts);
        if ($accounts->isEmpty()) {
            return [];
        }

        $ids = $accounts->pluck('id')->all();

        /** @var array<int, float> $txn */
        $txn = Transaction::whereIn('account_id', $ids)
            ->where('status', 'cleared')
            ->selectRaw('account_id, SUM(amount) as total')
            ->groupBy('account_id')
            ->pluck('total', 'account_id')
            ->map(fn ($v) => (float) $v)
            ->all();

        /** @var array<int, float> $out */
        $out = Transfer::whereIn('from_account_id', $ids)
            ->where('status', 'cleared')
            ->selectRaw('from_account_id, SUM(from_amount) as total')
            ->groupBy('from_account_id')
            ->pluck('total', 'from_account_id')
            ->map(fn ($v) => (float) $v)
            ->all();

        /** @var array<int, float> $in */
        $in = Transfer::whereIn('to_account_id', $ids)
            ->where('status', 'cleared')
            ->selectRaw('to_account_id, SUM(to_amount) as total')
            ->groupBy('to_account_id')
            ->pluck('total', 'to_account_id')
            ->map(fn ($v) => (float) $v)
            ->all();

        $result = [];
        foreach ($accounts as $a) {
            $id = (int) $a->id;
            $result[$id] = round(
                (float) $a->opening_balance
                + ($txn[$id] ?? 0.0)
                - ($out[$id] ?? 0.0)
                + ($in[$id] ?? 0.0),
                2,
            );
        }

        return $result;
    }
}

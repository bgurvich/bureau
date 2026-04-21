<?php

namespace App\Support\PayPal;

use App\Models\Household;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Links PayPal child-transactions to the bank-row that funded them.
 *
 * PayPal rolls up N merchant charges on a given PayPal account into 1 bank
 * debit labeled "PAYPAL *…" (or "PAYPAL TRANSFER"). This service finds bank
 * Transactions (not import_source='paypal') whose description matches a
 * PayPal-y regex, then searches PayPal-sourced rows (import_source='paypal')
 * within a ±3d window whose SUM matches the bank amount. On a unique
 * 1-to-N solution, each PayPal row gets funded_by_transaction_id set to the
 * bank row. Ambiguity → skip (user disambiguates manually).
 */
class PayPalReconciliation
{
    private const DATE_TOLERANCE_DAYS = 3;

    private const AMOUNT_TOLERANCE = 0.01;

    public function reconcile(Household $household): int
    {
        $bankCandidates = Transaction::query()
            ->where('household_id', $household->id)
            ->where(fn ($q) => $q->whereNull('import_source')->orWhere('import_source', '!=', 'paypal'))
            ->whereRaw('LOWER(description) LIKE ?', ['%paypal%'])
            ->whereDoesntHave('fundedChildren')
            ->orderBy('occurred_on')
            ->get(['id', 'account_id', 'amount', 'currency', 'occurred_on', 'description']);

        $linked = 0;
        foreach ($bankCandidates as $bank) {
            $count = $this->linkChildrenFor($household, $bank);
            $linked += $count;
        }

        return $linked;
    }

    /**
     * Returns how many child rows were linked to this bank transaction.
     */
    private function linkChildrenFor(Household $household, Transaction $bank): int
    {
        $targetAbs = round(abs((float) $bank->amount), 2);
        $bankDate = CarbonImmutable::parse($bank->occurred_on);

        // Candidate PayPal children: same sign as bank (bank debit = negative,
        // children also negative, since they were outflows from PayPal to the
        // merchant). Within ±3d.
        $sign = (float) $bank->amount < 0 ? '<' : '>';
        $children = Transaction::query()
            ->where('household_id', $household->id)
            ->where('import_source', 'paypal')
            ->whereNull('funded_by_transaction_id')
            ->where('currency', $bank->currency)
            ->where('amount', $sign, 0)
            ->whereBetween('occurred_on', [
                $bankDate->subDays(self::DATE_TOLERANCE_DAYS)->toDateString(),
                $bankDate->addDays(self::DATE_TOLERANCE_DAYS)->toDateString(),
            ])
            ->orderBy('occurred_on')
            ->get(['id', 'amount', 'occurred_on']);

        if ($children->isEmpty()) {
            return 0;
        }

        // Find the smallest subset whose absolute-sum equals targetAbs ±0.01.
        // Bounded brute force — if more than 12 children in the window, bail
        // out (subset enumeration is 2^n, and 2^12 is already 4k combinations).
        if ($children->count() > 12) {
            return 0;
        }

        $subset = $this->findSubset(
            $children->map(fn ($c) => round(abs((float) $c->amount), 2))->all(),
            $targetAbs,
            self::AMOUNT_TOLERANCE,
        );
        if ($subset === null) {
            return 0;
        }

        DB::transaction(function () use ($children, $subset, $bank) {
            foreach ($subset as $idx) {
                /** @var Transaction $child */
                $child = $children[$idx];
                $child->forceFill(['funded_by_transaction_id' => $bank->id])->save();
            }
        });

        return count($subset);
    }

    /**
     * Smallest subset of positive amounts whose sum is within tolerance of
     * target. Returns indexes into $amounts. Null when no such subset exists
     * (including ambiguous — two distinct subsets both match).
     *
     * @param  array<int, float>  $amounts
     * @return array<int, int>|null
     */
    private function findSubset(array $amounts, float $target, float $tolerance): ?array
    {
        $n = count($amounts);
        $best = null;
        $multiple = false;

        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $sum = 0.0;
            $indexes = [];
            for ($i = 0; $i < $n; $i++) {
                if (($mask >> $i) & 1) {
                    $sum += $amounts[$i];
                    $indexes[] = $i;
                }
            }
            if (abs($sum - $target) > $tolerance) {
                continue;
            }
            if ($best === null) {
                $best = $indexes;

                continue;
            }
            // Another subset matches — ambiguous. We could prefer the
            // smallest, but that can still be tied. Abort to stay honest.
            if (count($indexes) !== count($best)) {
                // Prefer smaller subset if strictly smaller.
                if (count($indexes) < count($best)) {
                    $best = $indexes;
                    $multiple = false;
                }

                continue;
            }
            $multiple = true;
        }

        return $multiple ? null : $best;
    }
}

<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Transaction;
use App\Models\Transfer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Detects bank-to-bank transfer pairs among Transactions and collapses them
 * into Transfer records. Two Transactions form a pair when:
 *   - they're in DIFFERENT accounts under the same household,
 *   - their amounts are opposite-signed with matching magnitude (±0.01 for
 *     rounding; same-currency only in v1),
 *   - occurred_on is within ±3 days,
 *   - neither is already referenced by a Transfer (from_transaction_id or
 *     to_transaction_id).
 *
 * Ambiguity rule: if a debit has multiple candidate credits, we skip — a
 * human should disambiguate. Exact 1:1 pairs only.
 *
 * Same-currency only for v1. Cross-currency pairs (e.g. moving USD→CAD)
 * need FX conversion awareness; scope for a later pass.
 */
class TransferPairing
{
    private const DATE_TOLERANCE_DAYS = 3;

    private const AMOUNT_TOLERANCE = 0.01;

    public function pair(Household $household): int
    {
        $pairedIds = Transfer::query()
            ->where('household_id', $household->id)
            ->whereNotNull('from_transaction_id')
            ->pluck('from_transaction_id')
            ->merge(
                Transfer::query()->where('household_id', $household->id)
                    ->whereNotNull('to_transaction_id')
                    ->pluck('to_transaction_id')
            )->all();

        $debits = Transaction::query()
            ->where('household_id', $household->id)
            ->where('amount', '<', 0)
            ->whereNotIn('id', $pairedIds)
            ->orderBy('occurred_on')
            ->get(['id', 'account_id', 'amount', 'currency', 'occurred_on', 'description']);

        $created = 0;
        foreach ($debits as $debit) {
            $match = $this->findSingleCreditMatch($household, $debit, $pairedIds);
            if (! $match) {
                continue;
            }

            DB::transaction(function () use ($household, $debit, $match, &$created, &$pairedIds) {
                Transfer::create([
                    'household_id' => $household->id,
                    'occurred_on' => $debit->occurred_on,
                    'from_account_id' => $debit->account_id,
                    'from_amount' => $debit->amount,
                    'from_currency' => $debit->currency,
                    'from_transaction_id' => $debit->id,
                    'to_account_id' => $match->account_id,
                    'to_amount' => $match->amount,
                    'to_currency' => $match->currency,
                    'to_transaction_id' => $match->id,
                    'description' => $debit->description ?? $match->description,
                    'status' => 'cleared',
                ]);
                $pairedIds[] = $debit->id;
                $pairedIds[] = $match->id;
                $created++;
            });
        }

        return $created;
    }

    /**
     * @param  array<int, int>  $excludedIds
     */
    private function findSingleCreditMatch(Household $household, Transaction $debit, array $excludedIds): ?Transaction
    {
        $absAmount = abs((float) $debit->amount);
        $debitOn = CarbonImmutable::parse($debit->occurred_on);

        $candidates = Transaction::query()
            ->where('household_id', $household->id)
            ->where('account_id', '!=', $debit->account_id)
            ->where('amount', '>', 0)
            ->where('currency', $debit->currency)
            ->whereNotIn('id', $excludedIds)
            ->whereBetween('amount', [$absAmount - self::AMOUNT_TOLERANCE, $absAmount + self::AMOUNT_TOLERANCE])
            ->whereBetween('occurred_on', [
                $debitOn->subDays(self::DATE_TOLERANCE_DAYS)->toDateString(),
                $debitOn->addDays(self::DATE_TOLERANCE_DAYS)->toDateString(),
            ])
            ->limit(5)
            ->get(['id', 'account_id', 'amount', 'currency', 'occurred_on', 'description']);

        if ($candidates->count() !== 1) {
            return null;
        }

        return $candidates->first();
    }
}

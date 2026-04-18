<?php

namespace App\Support;

use App\Models\RecurringProjection;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Tries to auto-link a freshly-created Transaction to the recurring_projection
 * row it almost certainly paid off. Match rule (all must hold):
 *   - same account
 *   - exact amount match
 *   - projection.due_on within ±$tolerance days of transaction.occurred_on
 *   - projection.status in (projected, overdue)
 *   - projection.matched_transaction_id is null (not already linked)
 *
 * Returns the linked projection on success, null if there's 0 or >1 hit.
 * Multi-hit ambiguity is deferred to a user picker (not yet implemented).
 */
class ProjectionMatcher
{
    public static function attempt(Transaction $transaction, int $toleranceDays = 3): ?RecurringProjection
    {
        if (! $transaction->account_id || ! $transaction->amount || ! $transaction->occurred_on) {
            return null;
        }

        $occurredOn = CarbonImmutable::parse($transaction->occurred_on);
        $from = $occurredOn->subDays($toleranceDays)->toDateString();
        $to = $occurredOn->addDays($toleranceDays)->toDateString();

        $candidates = RecurringProjection::query()
            ->whereIn('status', ['projected', 'overdue'])
            ->whereNull('matched_transaction_id')
            ->whereNull('matched_transfer_id')
            ->where('amount', $transaction->amount)
            ->whereDate('due_on', '>=', $from)
            ->whereDate('due_on', '<=', $to)
            ->whereHas('rule', fn ($q) => $q->where('account_id', $transaction->account_id))
            ->limit(2)
            ->get();

        if ($candidates->count() !== 1) {
            return null;
        }

        /** @var RecurringProjection $projection */
        $projection = $candidates->first();

        $projection->forceFill([
            'matched_transaction_id' => $transaction->id,
            'status' => 'matched',
            'matched_at' => now(),
            'unmatched_at' => null,
        ])->save();

        return $projection;
    }
}

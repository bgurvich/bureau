<?php

namespace App\Support;

use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Tries to auto-link a freshly-created Transaction to the recurring_projection
 * row it almost certainly paid off. Match rule (all must hold):
 *   - same account (via projection.rule.account_id)
 *   - exact amount match
 *   - projection.due_on within tolerance days of transaction.occurred_on,
 *     where tolerance is the rule's match_tolerance_days or the default
 *   - projection.status in (projected, overdue)
 *   - projection.matched_transaction_id is null (not already linked)
 *
 * Returns the linked projection on success, null if there's 0 or >1 hit.
 * Multi-hit ambiguity is deferred to a user picker (not yet implemented).
 */
class ProjectionMatcher
{
    public static function attempt(Transaction $transaction, int $defaultToleranceDays = 3): ?RecurringProjection
    {
        if (! $transaction->account_id || ! $transaction->amount || ! $transaction->occurred_on) {
            return null;
        }

        $occurredOn = CarbonImmutable::parse($transaction->occurred_on);

        // Broad pre-filter: widest window we might need = max(default, biggest
        // per-rule override among rules on this account). Narrows to the real
        // per-rule tolerance after load.
        $maxTolerance = max(
            $defaultToleranceDays,
            (int) RecurringRule::where('account_id', $transaction->account_id)
                ->max('match_tolerance_days')
        );

        $from = $occurredOn->subDays($maxTolerance)->toDateString();
        $to = $occurredOn->addDays($maxTolerance)->toDateString();

        $candidates = RecurringProjection::query()
            ->with('rule:id,account_id,match_tolerance_days')
            ->whereIn('status', ['projected', 'overdue'])
            ->whereNull('matched_transaction_id')
            ->whereNull('matched_transfer_id')
            ->where('amount', $transaction->amount)
            ->whereDate('due_on', '>=', $from)
            ->whereDate('due_on', '<=', $to)
            ->whereHas('rule', fn ($q) => $q->where('account_id', $transaction->account_id))
            ->limit(20)
            ->get();

        // Refine per-rule tolerance. A utility that drifts on a weekly cadence
        // might legitimately run ±7d; a landlord won't. The whereHas guard above
        // guarantees each candidate has its rule loaded.
        $filtered = $candidates->filter(function (RecurringProjection $p) use ($occurredOn, $defaultToleranceDays) {
            $tolerance = $p->rule->match_tolerance_days ?? $defaultToleranceDays;
            $diffDays = (int) abs($occurredOn->diffInDays($p->due_on));

            return $diffDays <= $tolerance;
        });

        if ($filtered->count() !== 1) {
            return null;
        }

        /** @var RecurringProjection $projection */
        $projection = $filtered->first();

        $projection->forceFill([
            'matched_transaction_id' => $transaction->id,
            'status' => 'matched',
            'matched_at' => now(),
            'unmatched_at' => null,
        ])->save();

        return $projection;
    }
}

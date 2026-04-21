<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use Carbon\CarbonImmutable;

/**
 * After a projection is matched to a transaction, check whether the
 * rule's last N matches all landed a consistent non-zero number of
 * days off from the projected due_on. If so, nudge the rule's
 * anchor_drift_days so future projections track reality instead of
 * the original RRULE anchor — without the user having to edit the
 * rule. The drift is applied additively by the projection generator
 * (see GenerateRecurringProjections::upsertProjection).
 *
 * Safeguards:
 *   - Requires at least MIN_CONSECUTIVE matches (default 3).
 *   - All deltas must share the same sign — a mix of early + late
 *     payments means the rule is on the right cadence and real-world
 *     noise is swinging around it; don't nudge in that case.
 *   - Only writes back when the new drift differs from the current
 *     one by at least MIN_CHANGE_DAYS, so borderline cases don't
 *     churn the column.
 */
final class ProjectionDriftDetector
{
    public const MIN_CONSECUTIVE = 3;

    public const MIN_CHANGE_DAYS = 1;

    public const MAX_DRIFT_DAYS = 28;

    public static function nudgeIfNeeded(RecurringRule $rule): void
    {
        $recent = RecurringProjection::query()
            ->where('rule_id', $rule->id)
            ->whereNotNull('matched_at')
            ->whereNotNull('matched_transaction_id')
            ->with('matchedTransaction:id,occurred_on')
            ->orderByDesc('matched_at')
            ->limit(self::MIN_CONSECUTIVE)
            ->get();

        if ($recent->count() < self::MIN_CONSECUTIVE) {
            return;
        }

        /** @var array<int, int> $deltas */
        $deltas = [];
        foreach ($recent as $projection) {
            $transaction = $projection->matchedTransaction;
            if (! $transaction || ! $transaction->occurred_on || ! $projection->due_on) {
                return;
            }
            // Signed: positive = transaction landed AFTER the due_on;
            // negative = transaction landed BEFORE. This is the offset
            // we'd need to add to due_on to hit the actual payment
            // date, i.e. the direction the anchor should shift.
            $due = CarbonImmutable::parse($projection->due_on);
            $occurred = CarbonImmutable::parse($transaction->occurred_on);
            $deltas[] = (int) $due->diffInDays($occurred, false);
        }

        // Reject mixed signs — only nudge when reality is consistently
        // biased one way. Zero doesn't count either: the current anchor
        // IS right.
        $signs = array_unique(array_map(fn (int $d) => $d <=> 0, $deltas));
        if (count($signs) !== 1 || $signs[0] === 0) {
            return;
        }

        $avg = (int) round(array_sum($deltas) / count($deltas));
        // Clamp against the safety cap — a month-sized drift almost
        // always means the history is wrong (or the rule itself was),
        // not that we should apply 60 days of offset.
        if (abs($avg) > self::MAX_DRIFT_DAYS) {
            return;
        }

        $current = (int) ($rule->anchor_drift_days ?? 0);
        if (abs($avg - $current) < self::MIN_CHANGE_DAYS) {
            return;
        }

        $rule->forceFill(['anchor_drift_days' => $avg])->save();
    }
}

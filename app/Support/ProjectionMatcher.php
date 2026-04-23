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
    /**
     * Thin wrapper around resolve() for callers that only care about the
     * "was it linked?" outcome — the bulk of the callsites (PayPal sync,
     * statements import, mobile inbox) don't need the ambiguity info.
     * Returns the linked projection or null.
     */
    public static function attempt(Transaction $transaction, int $defaultToleranceDays = 3): ?RecurringProjection
    {
        return self::resolve($transaction, $defaultToleranceDays)->linked;
    }

    /**
     * Match a transaction against eligible projections, returning a rich
     * result so the caller can distinguish no-match from ambiguous-match:
     *
     *   1 eligible projection   → auto-link, return linked result.
     *   ≥2 eligible projections → do not auto-link (avoid wrong guess).
     *                             Return candidates for a UI picker.
     *   0 eligible projections  → fall back to fuzzy rule matching; if
     *                             that finds exactly one rule, materialize
     *                             its projection and link.
     */
    public static function resolve(Transaction $transaction, int $defaultToleranceDays = 3): ProjectionMatchResult
    {
        if (! $transaction->account_id || ! $transaction->amount || ! $transaction->occurred_on) {
            return ProjectionMatchResult::miss();
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
            ->with('rule:id,account_id,match_tolerance_days,title,counterparty_contact_id')
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

        if ($filtered->count() === 1) {
            /** @var RecurringProjection $projection */
            $projection = $filtered->first();
            $projection->forceFill([
                'matched_transaction_id' => $transaction->id,
                'status' => 'matched',
                'matched_at' => now(),
                'unmatched_at' => null,
            ])->save();

            if ($projection->rule) {
                ProjectionDriftDetector::nudgeIfNeeded($projection->rule);
            }

            return ProjectionMatchResult::linked($projection);
        }

        if ($filtered->count() >= 2) {
            // Two or more projections look equally plausible. Don't guess —
            // the UI will surface a "which bill did this pay?" picker.
            return ProjectionMatchResult::ambiguous($filtered->all());
        }

        // No exact pre-generated projection matched. Try the fuzzy fallback:
        // a plausibly-matching active rule may simply not have a projection
        // for this cycle yet (e.g. user skipped the scheduler for a month,
        // or this is the first charge after creating the rule).
        $fuzzy = self::attemptFuzzy($transaction, $defaultToleranceDays);

        return $fuzzy !== null
            ? ProjectionMatchResult::linked($fuzzy)
            : ProjectionMatchResult::miss();
    }

    /**
     * When no pre-generated projection matches exactly, see if an active
     * RecurringRule plausibly corresponds to this transaction anyway:
     *   - same account,
     *   - counterparty matches OR title token appears in description,
     *   - amount within ±10%,
     *   - transaction.occurred_on is inside a reasonable cadence window from dtstart.
     *
     * On a single fuzzy hit, materialize a projection for the matched cycle
     * and link it. Multi-hit ambiguity → no-op (keeps stdout honest).
     */
    private static function attemptFuzzy(Transaction $transaction, int $defaultToleranceDays): ?RecurringProjection
    {
        $occurredOn = CarbonImmutable::parse($transaction->occurred_on);
        $amount = (float) $transaction->amount;

        $desc = mb_strtolower((string) ($transaction->description ?? ''));
        $candidates = RecurringRule::query()
            ->where('active', true)
            ->where('account_id', $transaction->account_id)
            ->get(['id', 'title', 'amount', 'rrule', 'dtstart', 'counterparty_contact_id', 'match_tolerance_days']);

        $hits = [];
        foreach ($candidates as $rule) {
            if (! self::counterpartyOrDescriptionMatches($rule, $transaction, $desc)) {
                continue;
            }
            if (! self::amountWithinBand($amount, (float) $rule->amount, 0.10)) {
                continue;
            }
            $tolerance = $rule->match_tolerance_days ?? $defaultToleranceDays;
            $expected = self::nearestCadenceDate($rule, $occurredOn);
            if ($expected === null) {
                continue;
            }
            if ((int) abs($occurredOn->diffInDays($expected)) > $tolerance) {
                continue;
            }
            $hits[] = ['rule' => $rule, 'expected' => $expected];
        }

        if (count($hits) !== 1) {
            return null;
        }

        $rule = $hits[0]['rule'];
        /** @var CarbonImmutable $expected */
        $expected = $hits[0]['expected'];

        $projection = RecurringProjection::create([
            'rule_id' => $rule->id,
            'due_on' => $expected->toDateString(),
            'issued_on' => $expected->toDateString(),
            'amount' => $rule->amount,
            'currency' => $rule->currency ?? 'USD',
            'status' => 'matched',
            'matched_transaction_id' => $transaction->id,
            'matched_at' => now(),
            'autopay' => (bool) ($rule->autopay ?? false),
        ]);

        ProjectionDriftDetector::nudgeIfNeeded($rule);

        return $projection;
    }

    private static function counterpartyOrDescriptionMatches(RecurringRule $rule, Transaction $transaction, string $descLower): bool
    {
        if ($rule->counterparty_contact_id
            && $transaction->counterparty_contact_id
            && (int) $rule->counterparty_contact_id === (int) $transaction->counterparty_contact_id) {
            return true;
        }
        $title = mb_strtolower((string) $rule->title);
        if ($title === '' || $descLower === '') {
            return false;
        }
        // Token overlap: any title word ≥4 chars that appears in description.
        foreach (preg_split('/[^a-z0-9]+/', $title) ?: [] as $word) {
            if (mb_strlen($word) >= 4 && str_contains($descLower, $word)) {
                return true;
            }
        }

        return false;
    }

    private static function amountWithinBand(float $actual, float $expected, float $tolerance): bool
    {
        if ($expected == 0.0) {
            return $actual == 0.0;
        }
        $ratio = abs($actual - $expected) / max(abs($expected), 0.01);

        return $ratio <= $tolerance;
    }

    /**
     * Given a rule and a transaction date, return the nearest expected
     * recurrence date. For v1, supports the common RRULE shapes Secretaire
     * produces: monthly, weekly, biweekly, yearly. Fallback: dtstart.
     */
    private static function nearestCadenceDate(RecurringRule $rule, CarbonImmutable $occurredOn): ?CarbonImmutable
    {
        $dtstart = $rule->dtstart ? CarbonImmutable::parse($rule->dtstart) : null;
        if (! $dtstart) {
            return null;
        }
        $rrule = (string) ($rule->rrule ?? '');

        if (preg_match('/FREQ=MONTHLY/i', $rrule)) {
            // nearest day-aligned month boundary from dtstart
            $target = $dtstart->setYear($occurredOn->year)->setMonth($occurredOn->month);
            if (abs($occurredOn->diffInDays($target)) <= 15) {
                return $target;
            }
            // If we're in the first half of month but dtstart day > now, try prev month
            $prev = $target->subMonth();

            return abs($occurredOn->diffInDays($prev)) < abs($occurredOn->diffInDays($target))
                ? $prev
                : $target;
        }
        if (preg_match('/FREQ=WEEKLY/i', $rrule)) {
            $interval = preg_match('/INTERVAL=(\d+)/i', $rrule, $m) ? max(1, (int) $m[1]) : 1;
            $days = $interval * 7;
            $delta = $occurredOn->diffInDays($dtstart);
            $cycles = (int) round($delta / $days);

            return $dtstart->addDays($cycles * $days);
        }
        if (preg_match('/FREQ=YEARLY/i', $rrule)) {
            return $dtstart->setYear($occurredOn->year);
        }

        // One-off rules (FREQ=DAILY;COUNT=1) — dtstart is the only candidate.
        return $dtstart;
    }
}

<?php

namespace App\Support;

use App\Models\Household;
use App\Models\RecurringDiscovery;
use App\Models\RecurringRule;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Deterministic pattern discovery over a household's transactions.
 *
 * Groups transactions by `(account, counterparty OR normalized description
 * token)`, classifies the median inter-transaction delta against canonical
 * cadences, and emits `RecurringDiscovery` candidates. Idempotent across
 * reruns: repeat-safe via `signature_hash` uniqueness — previously-dismissed
 * or previously-accepted candidates stay in their states.
 *
 * Explicitly skips groups already covered by an active `RecurringRule` so
 * the user isn't nagged about things Secretaire already tracks.
 */
class RecurringPatternDiscovery
{
    private const MIN_OCCURRENCES = 3;

    private const MIN_SPAN_DAYS = 90;

    private const AMOUNT_TOLERANCE = 0.10; // ±10% band for "consistent"

    /** @var array<string, int> */
    private const CADENCE_TARGETS = [
        'weekly' => 7,
        'biweekly' => 14,
        'monthly' => 30,          // median 28–31 mapped to 30
        'quarterly' => 91,
        'yearly' => 365,
    ];

    private const CADENCE_TOLERANCE_DAYS = [
        'weekly' => 1,
        'biweekly' => 2,
        'monthly' => 4,
        'quarterly' => 7,
        'yearly' => 15,
    ];

    /**
     * Discover new recurring patterns for the given household and upsert
     * proposals into recurring_discoveries. Returns the count of new pending
     * discoveries created on this run (excludes existing dismissed/accepted
     * which are left untouched).
     */
    public function discover(Household $household): int
    {
        CurrentHousehold::set($household);

        $transactions = Transaction::query()
            ->where('household_id', $household->id)
            ->whereNotNull('account_id')
            ->whereNotNull('occurred_on')
            ->orderBy('occurred_on')
            ->get([
                'id', 'account_id', 'counterparty_contact_id',
                'description', 'amount', 'occurred_on',
            ]);

        $groups = $this->groupTransactions($transactions);
        $rules = RecurringRule::where('active', true)->get([
            'id', 'account_id', 'counterparty_contact_id', 'title', 'amount', 'rrule',
        ]);

        $created = 0;
        foreach ($groups as $group) {
            if (count($group['transactions']) < self::MIN_OCCURRENCES) {
                continue;
            }
            $analysis = $this->analyzeGroup($group['transactions']);
            if ($analysis === null) {
                continue;
            }
            // Guard: skip if an active rule already covers this pattern.
            if ($this->ruleCoversGroup($rules, $group, $analysis)) {
                continue;
            }

            $signature = hash('sha256', implode('|', [
                $household->id,
                $group['account_id'],
                $group['counterparty_contact_id'] ?? 0,
                $group['description_fingerprint'],
                $analysis['cadence'],
            ]));

            $existing = RecurringDiscovery::where('household_id', $household->id)
                ->where('signature_hash', $signature)
                ->first();

            if ($existing) {
                // Refresh stats on existing rows without resetting status.
                $existing->forceFill([
                    'median_amount' => $analysis['median_amount'],
                    'amount_variance' => $analysis['amount_variance'],
                    'occurrence_count' => $analysis['count'],
                    'first_seen_on' => $analysis['first'],
                    'last_seen_on' => $analysis['last'],
                    'score' => $analysis['score'],
                ])->save();

                continue;
            }

            RecurringDiscovery::create([
                'household_id' => $household->id,
                'account_id' => $group['account_id'],
                'counterparty_contact_id' => $group['counterparty_contact_id'],
                'description_fingerprint' => $group['description_fingerprint'],
                'cadence' => $analysis['cadence'],
                'median_amount' => $analysis['median_amount'],
                'amount_variance' => $analysis['amount_variance'],
                'occurrence_count' => $analysis['count'],
                'first_seen_on' => $analysis['first'],
                'last_seen_on' => $analysis['last'],
                'score' => $analysis['score'],
                'status' => 'pending',
                'signature_hash' => $signature,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, array{account_id: int, counterparty_contact_id: ?int, description_fingerprint: string, transactions: array<int, Transaction>}>
     */
    private function groupTransactions(Collection $transactions): array
    {
        $buckets = [];
        foreach ($transactions as $t) {
            $fingerprint = $this->fingerprintDescription((string) ($t->description ?? ''));
            if ($fingerprint === '' && ! $t->counterparty_contact_id) {
                continue;
            }
            $key = $t->account_id.'|'.($t->counterparty_contact_id ?? 'cp0').'|'.$fingerprint;
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'account_id' => (int) $t->account_id,
                    'counterparty_contact_id' => $t->counterparty_contact_id,
                    'description_fingerprint' => $fingerprint,
                    'transactions' => [],
                ];
            }
            $buckets[$key]['transactions'][] = $t;
        }

        return array_values($buckets);
    }

    /**
     * Normalize a free-text description into a stable, lowercased token
     * safe to group on: drop digits, punctuation, generic suffixes like
     * "#1234", "POS" markers, timestamps. Short or fully-numeric outputs
     * return empty → the group falls back to counterparty matching only.
     */
    private function fingerprintDescription(string $raw): string
    {
        $lower = mb_strtolower($raw);
        $lower = (string) preg_replace('/[^a-z\s]+/', ' ', $lower);
        $words = preg_split('/\s+/', trim($lower)) ?: [];
        $meaningful = array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 4));
        // Take up to the first two meaningful tokens — enough to identify
        // "netflix" / "spotify premium" / "amazon prime" without over-binding
        // (same merchant with different trailing codes still collapses).
        $slice = array_slice($meaningful, 0, 2);

        return implode(' ', $slice);
    }

    /**
     * @param  array<int, Transaction>  $txs
     * @return array{cadence: string, median_amount: float, amount_variance: float, count: int, first: string, last: string, score: float}|null
     */
    private function analyzeGroup(array $txs): ?array
    {
        $dates = array_map(fn (Transaction $t) => CarbonImmutable::parse($t->occurred_on), $txs);
        sort($dates);
        $count = count($dates);
        if ($count < self::MIN_OCCURRENCES) {
            return null;
        }
        $spanDays = (int) $dates[0]->diffInDays(end($dates));
        if ($spanDays < self::MIN_SPAN_DAYS) {
            return null;
        }

        $deltas = [];
        for ($i = 1; $i < $count; $i++) {
            $deltas[] = (int) $dates[$i - 1]->diffInDays($dates[$i]);
        }
        sort($deltas);
        $medianDelta = $deltas[(int) floor(count($deltas) / 2)];

        $cadence = null;
        foreach (self::CADENCE_TARGETS as $name => $target) {
            if (abs($medianDelta - $target) <= self::CADENCE_TOLERANCE_DAYS[$name]) {
                $cadence = $name;
                break;
            }
        }
        if ($cadence === null) {
            return null;
        }

        $amounts = array_map(fn (Transaction $t) => (float) $t->amount, $txs);
        sort($amounts);
        $medianAmount = $amounts[(int) floor(count($amounts) / 2)];
        $amountVariance = 0.0;
        if ($medianAmount != 0.0) {
            $amountVariance = array_sum(array_map(
                fn (float $a) => abs($a - $medianAmount) / max(abs($medianAmount), 0.01),
                $amounts
            )) / count($amounts);
        }

        // Score: reward tight cadence + consistent amount + many occurrences.
        $cadenceTightness = 1.0 - min(1.0, abs($medianDelta - self::CADENCE_TARGETS[$cadence]) / max(self::CADENCE_TOLERANCE_DAYS[$cadence], 1));
        $amountConsistency = $amountVariance <= self::AMOUNT_TOLERANCE ? 1.0 : max(0.0, 1.0 - ($amountVariance - self::AMOUNT_TOLERANCE));
        $score = round($cadenceTightness * $amountConsistency * sqrt($count), 4);

        return [
            'cadence' => $cadence,
            'median_amount' => (float) $medianAmount,
            'amount_variance' => (float) $amountVariance,
            'count' => $count,
            'first' => $dates[0]->toDateString(),
            'last' => end($dates)->toDateString(),
            'score' => (float) $score,
        ];
    }

    /**
     * @param  Collection<int, RecurringRule>  $rules
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $analysis
     */
    private function ruleCoversGroup(Collection $rules, array $group, array $analysis): bool
    {
        $groupFp = (string) $group['description_fingerprint'];
        foreach ($rules as $rule) {
            if ((int) $rule->account_id !== (int) $group['account_id']) {
                continue;
            }
            $cpMatch = $rule->counterparty_contact_id
                && $group['counterparty_contact_id']
                && (int) $rule->counterparty_contact_id === (int) $group['counterparty_contact_id'];
            $titleFp = $this->fingerprintDescription((string) $rule->title);
            $titleMatch = $groupFp !== '' && $titleFp !== '' && $titleFp === $groupFp;
            if (! $cpMatch && ! $titleMatch) {
                continue;
            }
            // Amount within ±20% (rules can drift more than discoveries want to allow)
            if ($rule->amount !== null && abs($rule->amount - $analysis['median_amount']) / max(abs((float) $rule->amount), 0.01) > 0.2) {
                continue;
            }
            // Cadence match
            if (! $this->ruleCadenceMatches($rule, (string) $analysis['cadence'])) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function ruleCadenceMatches(RecurringRule $rule, string $cadence): bool
    {
        $rrule = (string) ($rule->rrule ?? '');

        return match ($cadence) {
            'weekly' => (bool) preg_match('/FREQ=WEEKLY(?![A-Z])/i', $rrule) && ! preg_match('/INTERVAL=2/i', $rrule),
            'biweekly' => (bool) preg_match('/FREQ=WEEKLY.*INTERVAL=2/is', $rrule),
            'monthly' => (bool) preg_match('/FREQ=MONTHLY/i', $rrule),
            'quarterly' => (bool) preg_match('/FREQ=MONTHLY.*INTERVAL=3/is', $rrule),
            'yearly' => (bool) preg_match('/FREQ=YEARLY/i', $rrule),
            default => false,
        };
    }
}

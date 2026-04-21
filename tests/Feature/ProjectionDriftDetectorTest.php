<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Support\ProjectionDriftDetector;
use Carbon\CarbonImmutable;

function makeRuleWithMatchHistory(array $deltas): RecurringRule
{
    $account = Account::create([
        'type' => 'checking', 'name' => 'Everyday',
        'currency' => 'USD', 'opening_balance' => 0,
    ]);

    $rule = RecurringRule::create([
        'kind' => 'bill',
        'title' => 'Landlord',
        'amount' => 1500,
        'currency' => 'USD',
        'account_id' => $account->id,
        'dtstart' => '2026-01-01',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        'active' => true,
    ]);

    foreach ($deltas as $i => $delta) {
        // Project for Feb/Mar/Apr/…
        $dueOn = CarbonImmutable::parse('2026-02-01')->addMonthsNoOverflow($i)->toDateString();
        $txnDate = CarbonImmutable::parse($dueOn)->addDays($delta)->toDateString();

        $txn = Transaction::create([
            'account_id' => $account->id,
            'occurred_on' => $txnDate,
            'amount' => 1500,
            'currency' => 'USD',
            'status' => 'cleared',
        ]);

        RecurringProjection::create([
            'rule_id' => $rule->id,
            'issued_on' => $dueOn,
            'due_on' => $dueOn,
            'amount' => 1500,
            'currency' => 'USD',
            'status' => 'matched',
            'matched_transaction_id' => $txn->id,
            'matched_at' => now()->subDays(count($deltas) - $i),
        ]);
    }

    return $rule;
}

it('nudges anchor_drift_days when the last 3 matches all drift the same way', function () {
    authedInHousehold();

    // Three months of the rent landing 4 days late.
    $rule = makeRuleWithMatchHistory([4, 4, 5]);

    ProjectionDriftDetector::nudgeIfNeeded($rule);

    expect($rule->fresh()->anchor_drift_days)->toBe(4);
});

it('does not nudge when match history has mixed signs', function () {
    authedInHousehold();

    // +3 / -2 / +4 — noise around the anchor, not a bias.
    $rule = makeRuleWithMatchHistory([3, -2, 4]);

    ProjectionDriftDetector::nudgeIfNeeded($rule);

    expect($rule->fresh()->anchor_drift_days)->toBeNull();
});

it('does not nudge when fewer than 3 matches exist', function () {
    authedInHousehold();

    $rule = makeRuleWithMatchHistory([5, 5]);

    ProjectionDriftDetector::nudgeIfNeeded($rule);

    expect($rule->fresh()->anchor_drift_days)->toBeNull();
});

it('does not nudge when every match is exactly on the anchor', function () {
    authedInHousehold();

    $rule = makeRuleWithMatchHistory([0, 0, 0]);

    ProjectionDriftDetector::nudgeIfNeeded($rule);

    expect($rule->fresh()->anchor_drift_days)->toBeNull();
});

it('refuses nudges larger than MAX_DRIFT_DAYS (likely bad data, not a real drift)', function () {
    authedInHousehold();

    // A full month of drift looks more like the history is wrong than
    // the cadence — hold off.
    $rule = makeRuleWithMatchHistory([30, 30, 30]);

    ProjectionDriftDetector::nudgeIfNeeded($rule);

    expect($rule->fresh()->anchor_drift_days)->toBeNull();
});

it('updates anchor_drift_days when the learned drift changes by >= 1 day', function () {
    authedInHousehold();

    $rule = makeRuleWithMatchHistory([3, 3, 3]);
    $rule->forceFill(['anchor_drift_days' => 3])->save();

    // A fresh run with the same data is a no-op.
    ProjectionDriftDetector::nudgeIfNeeded($rule);
    expect($rule->fresh()->anchor_drift_days)->toBe(3);

    // But if reality shifts — say +5 now — we update.
    $account = $rule->account;
    $txn = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-05-06',
        'amount' => 1500,
        'currency' => 'USD',
        'status' => 'cleared',
    ]);
    RecurringProjection::create([
        'rule_id' => $rule->id,
        'issued_on' => '2026-05-01',
        'due_on' => '2026-05-01',
        'amount' => 1500,
        'currency' => 'USD',
        'status' => 'matched',
        'matched_transaction_id' => $txn->id,
        'matched_at' => now()->addDay(), // most recent
    ]);

    ProjectionDriftDetector::nudgeIfNeeded($rule);
    // Last 3 deltas are now 3, 3, 5 → avg 3.66 → rounds to 4.
    expect($rule->fresh()->anchor_drift_days)->toBe(4);
});

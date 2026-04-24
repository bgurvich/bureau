<?php

declare(strict_types=1);

use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Subscription;
use Illuminate\Support\Facades\Artisan;

/**
 * Anchored regression test for the sign-flip bug the user hit during
 * early development — positive income rules persisted as negative,
 * positive-amount subscriptions regenerated from negative-amount bill
 * rules, etc. Convention: negative = outflow (expense / bill),
 * positive = inflow (income). Everything in the pipeline must
 * preserve the declared sign.
 */
it('preserves a positive income amount through the full pipeline', function () {
    authedInHousehold();

    $rule = RecurringRule::create([
        'title' => 'Paycheck',
        'kind' => 'income',
        'rrule' => 'FREQ=MONTHLY',
        'dtstart' => now()->subMonths(2)->toDateString(),
        'amount' => 5000,
        'currency' => 'USD',
    ]);

    expect((float) $rule->fresh()->amount)->toBe(5000.0);

    Artisan::call('recurring:project', ['--household' => $rule->household_id, '--horizon' => 60, '--backfill' => 30]);

    $projection = RecurringProjection::where('rule_id', $rule->id)->first();
    expect($projection)->not->toBeNull();
    expect((float) $projection->amount)->toBe(5000.0);

    // Income rules don't auto-create a Subscription — subscriptions
    // model outflows only. Confirm none was spawned.
    expect(Subscription::where('recurring_rule_id', $rule->id)->exists())->toBeFalse();
});

it('preserves a negative bill amount through the full pipeline', function () {
    authedInHousehold();

    $rule = RecurringRule::create([
        'title' => 'Rent',
        'kind' => 'bill',
        'rrule' => 'FREQ=MONTHLY',
        'dtstart' => now()->subMonths(2)->toDateString(),
        'amount' => -2200,
        'currency' => 'USD',
    ]);

    expect((float) $rule->fresh()->amount)->toBe(-2200.0);

    Artisan::call('recurring:project', ['--household' => $rule->household_id, '--horizon' => 60, '--backfill' => 30]);
    Artisan::call('subscriptions:backfill');

    $projection = RecurringProjection::where('rule_id', $rule->id)->first();
    expect($projection)->not->toBeNull();
    expect((float) $projection->amount)->toBe(-2200.0);

    // Outflow rules auto-create a Subscription via the backfill
    // command — its cached monthly cost should carry the negative
    // sign so the ledger convention holds end-to-end.
    $sub = Subscription::where('recurring_rule_id', $rule->id)->first();
    expect($sub)->not->toBeNull();
    expect((float) $sub->monthly_cost_cached)->toBeLessThan(0);
});

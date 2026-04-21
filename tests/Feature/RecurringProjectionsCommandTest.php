<?php

use App\Models\Account;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;

function setupForRecurring(): array
{
    $user = authedInHousehold('Rec Test');
    $account = Account::create([
        'type' => 'checking', 'name' => 'Main', 'institution' => '—',
        'currency' => 'USD', 'opening_balance' => 0,
    ]);

    return [CurrentHousehold::get(), $user, $account];
}

it('generates future projections as projected and past ones as overdue', function () {
    CarbonImmutable::setTestNow('2026-04-17');
    [$household, , $account] = setupForRecurring();

    RecurringRule::create([
        'kind' => 'bill', 'title' => 'Netflix',
        'amount' => -15.49, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=12',
        'dtstart' => '2026-01-12',
        'account_id' => $account->id,
    ]);

    $this->artisan('recurring:project', [
        '--horizon' => 60,
        '--backfill' => 30,
        '--household' => $household->id,
    ])->assertSuccessful();

    // Today = 2026-04-17, backfill=30 → from = 2026-03-18
    // Rule dtstart = 2026-01-12, so projections start at first BYMONTHDAY=12
    // on or after 2026-03-18: Apr 12, May 12, Jun 12 fit in the 60-day horizon.
    $projections = RecurringProjection::orderBy('due_on')->get();
    expect($projections)->toHaveCount(3);

    expect($projections[0]->due_on->toDateString())->toBe('2026-04-12')
        ->and($projections[0]->status)->toBe('overdue') // Apr 12 < today
        ->and((float) $projections[0]->amount)->toBe(-15.49);

    expect($projections[2]->due_on->toDateString())->toBe('2026-06-12')
        ->and($projections[2]->status)->toBe('projected');

    CarbonImmutable::setTestNow();
});

it('is idempotent — rerunning does not duplicate rows', function () {
    CarbonImmutable::setTestNow('2026-04-17');
    [$household, , $account] = setupForRecurring();

    RecurringRule::create([
        'kind' => 'bill', 'title' => 'Rent',
        'amount' => -2200, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        'dtstart' => '2026-01-01',
        'account_id' => $account->id,
    ]);

    $this->artisan('recurring:project', ['--household' => $household->id])->assertSuccessful();
    $firstRun = RecurringProjection::count();

    $this->artisan('recurring:project', ['--household' => $household->id])->assertSuccessful();
    $secondRun = RecurringProjection::count();

    expect($secondRun)->toBe($firstRun);

    CarbonImmutable::setTestNow();
});

it('promotes past projected rows to overdue when the command re-runs later', function () {
    CarbonImmutable::setTestNow('2026-04-10');
    [$household, , $account] = setupForRecurring();

    RecurringRule::create([
        'kind' => 'bill', 'title' => 'Rent',
        'amount' => -2200, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=15',
        'dtstart' => '2026-04-15',
        'account_id' => $account->id,
    ]);

    $this->artisan('recurring:project', ['--household' => $household->id])->assertSuccessful();
    $initial = RecurringProjection::whereDate('due_on', '2026-04-15')->first();
    expect($initial->status)->toBe('projected');

    CarbonImmutable::setTestNow('2026-04-20');
    $this->artisan('recurring:project', ['--household' => $household->id])->assertSuccessful();
    $promoted = RecurringProjection::whereDate('due_on', '2026-04-15')->first();
    expect($promoted->status)->toBe('overdue');

    CarbonImmutable::setTestNow();
});

it('leaves matched/paid/skipped rows untouched', function () {
    CarbonImmutable::setTestNow('2026-04-20');
    [$household, , $account] = setupForRecurring();

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Rent',
        'amount' => -2200, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        'dtstart' => '2026-01-01',
        'account_id' => $account->id,
    ]);

    RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => '2026-04-01',
        'amount' => -2200,
        'currency' => 'USD',
        'status' => 'paid',
    ]);

    $this->artisan('recurring:project', ['--household' => $household->id])->assertSuccessful();

    $row = RecurringProjection::whereDate('due_on', '2026-04-01')->first();
    expect($row->status)->toBe('paid');

    CarbonImmutable::setTestNow();
});

it('skips inactive rules', function () {
    CarbonImmutable::setTestNow('2026-04-17');
    [$household, , $account] = setupForRecurring();

    RecurringRule::create([
        'kind' => 'bill', 'title' => 'Old gym',
        'amount' => -35, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=5',
        'dtstart' => '2026-01-01',
        'account_id' => $account->id,
        'active' => false,
    ]);

    $this->artisan('recurring:project', ['--household' => $household->id])->assertSuccessful();

    expect(RecurringProjection::count())->toBe(0);

    CarbonImmutable::setTestNow();
});

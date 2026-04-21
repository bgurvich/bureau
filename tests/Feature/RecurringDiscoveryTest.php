<?php

use App\Models\Account;
use App\Models\RecurringDiscovery;
use App\Models\RecurringRule;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Support\RecurringPatternDiscovery;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

function seedNetflixRecurrence(Account $account, int $months = 7): void
{
    $today = CarbonImmutable::now();
    for ($i = 0; $i < $months; $i++) {
        Transaction::create([
            'account_id' => $account->id,
            'occurred_on' => $today->subMonths($months - $i)->setDay(15)->toDateString(),
            'amount' => -15.99,
            'currency' => 'USD',
            'description' => 'NETFLIX.COM 12345',
            'status' => 'cleared',
        ]);
    }
}

it('discovers a monthly recurring pattern from 7 months of Netflix charges', function () {
    $user = authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    seedNetflixRecurrence($account, 7);

    $household = $user->defaultHousehold;
    $discovery = app(RecurringPatternDiscovery::class);
    $created = $discovery->discover($household);
    expect($created)->toBe(1);

    $d = RecurringDiscovery::firstOrFail();
    expect($d->cadence)->toBe('monthly')
        ->and((float) $d->median_amount)->toBe(-15.99)
        ->and($d->status)->toBe('pending')
        ->and($d->occurrence_count)->toBe(7);
});

it('rediscovery is idempotent and preserves dismissal state', function () {
    $user = authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    seedNetflixRecurrence($account, 7);

    $discovery = app(RecurringPatternDiscovery::class);
    $discovery->discover($user->defaultHousehold);
    $d = RecurringDiscovery::firstOrFail();
    $d->forceFill(['status' => 'dismissed'])->save();

    $created = $discovery->discover($user->defaultHousehold);
    expect($created)->toBe(0)
        ->and(RecurringDiscovery::firstOrFail()->status)->toBe('dismissed');
});

it('skips patterns already covered by an active RecurringRule', function () {
    $user = authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    seedNetflixRecurrence($account, 7);

    // Pre-existing rule covers the same cadence + title fingerprint.
    RecurringRule::create([
        'kind' => 'bill', 'title' => 'NETFLIX COM',
        'amount' => -15.99, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=15',
        'dtstart' => '2026-01-15',
        'account_id' => $account->id,
        'active' => true,
    ]);

    $created = app(RecurringPatternDiscovery::class)->discover($user->defaultHousehold);
    expect($created)->toBe(0)
        ->and(RecurringDiscovery::count())->toBe(0);
});

it('requires at least 3 occurrences and 90-day span', function () {
    $user = authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    // Only 2 occurrences — insufficient
    seedNetflixRecurrence($account, 2);

    $created = app(RecurringPatternDiscovery::class)->discover($user->defaultHousehold);
    expect($created)->toBe(0);
});

it('recurring:discover artisan command runs discovery and reports counts', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    seedNetflixRecurrence($account, 7);

    $this->artisan('recurring:discover')
        ->assertExitCode(0);

    expect(RecurringDiscovery::count())->toBe(1);
});

it('dismissing a discovery via the Bills card persists the state', function () {
    $user = authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    seedNetflixRecurrence($account, 7);
    app(RecurringPatternDiscovery::class)->discover($user->defaultHousehold);

    $d = RecurringDiscovery::firstOrFail();
    Livewire::test('recurring-discoveries')->call('dismiss', $d->id);

    expect($d->fresh()->status)->toBe('dismissed');
});

it('acceptAsSubscription creates a RecurringRule and auto-creates a Subscription', function () {
    $user = authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    seedNetflixRecurrence($account, 7);
    app(RecurringPatternDiscovery::class)->discover($user->defaultHousehold);

    $d = RecurringDiscovery::firstOrFail();
    Livewire::test('recurring-discoveries')->call('acceptAsSubscription', $d->id);

    expect($d->fresh()->status)->toBe('accepted')
        ->and(RecurringRule::count())->toBeGreaterThanOrEqual(1)
        ->and(Subscription::count())->toBeGreaterThanOrEqual(1);
});

it('dismissAll marks every pending discovery dismissed', function () {
    $user = authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    seedNetflixRecurrence($account, 7);
    app(RecurringPatternDiscovery::class)->discover($user->defaultHousehold);
    expect(RecurringDiscovery::where('status', 'pending')->count())->toBeGreaterThan(0);

    Livewire::test('recurring-discoveries')->call('dismissAll');

    expect(RecurringDiscovery::where('status', 'pending')->count())->toBe(0);
});

<?php

use App\Models\Contact;
use App\Models\Contract;
use App\Models\RecurringRule;
use App\Models\Subscription;

it('regenerates subscriptions from active outflow rules', function () {
    authedInHousehold();

    RecurringRule::create([
        'title' => 'Netflix', 'kind' => 'expense', 'amount' => -15.99, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;INTERVAL=1', 'dtstart' => now(), 'active' => true,
    ]);
    RecurringRule::create([
        'title' => 'Spotify', 'kind' => 'expense', 'amount' => -9.99, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => true,
    ]);
    expect(Subscription::count())->toBe(2);

    // Manual edit that should be overwritten by regen.
    Subscription::first()->update(['name' => 'manual override']);

    $this->artisan('subscriptions:regenerate --force')->assertSuccessful();

    expect(Subscription::count())->toBe(2);
    // The manual "override" name is gone — regen restored the canonical
    // title from the rule.
    expect(Subscription::pluck('name')->all())->toContain('Netflix')
        ->toContain('Spotify')
        ->not->toContain('manual override');
});

it('skips inactive and income rules on regen', function () {
    authedInHousehold();

    RecurringRule::create([
        'title' => 'Active outflow', 'kind' => 'expense', 'amount' => -10, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => true,
    ]);
    RecurringRule::create([
        'title' => 'Paused outflow', 'kind' => 'expense', 'amount' => -10, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => false,
    ]);
    RecurringRule::create([
        'title' => 'Salary', 'kind' => 'income', 'amount' => 5000, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => true,
    ]);

    $this->artisan('subscriptions:regenerate --force')->assertSuccessful();

    expect(Subscription::count())->toBe(1)
        ->and(Subscription::first()->name)->toBe('Active outflow');
});

it('relinks contracts on regen', function () {
    authedInHousehold();
    $vendor = Contact::create(['kind' => 'org', 'display_name' => 'Netflix']);

    RecurringRule::create([
        'title' => 'Netflix', 'kind' => 'expense', 'amount' => -15.99, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => true,
        'counterparty_contact_id' => $vendor->id,
    ]);
    $contract = Contract::create([
        'title' => 'Netflix Premium', 'kind' => 'subscription', 'state' => 'active',
    ]);
    $contract->contacts()->attach($vendor->id, ['party_role' => 'counterparty']);

    $this->artisan('subscriptions:regenerate --force')->assertSuccessful();

    expect(Subscription::first()->contract_id)->toBe($contract->id);
});

it('dry-run leaves subscriptions untouched', function () {
    authedInHousehold();
    RecurringRule::create([
        'title' => 'Dry', 'kind' => 'expense', 'amount' => -5, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => true,
    ]);
    $before = Subscription::count();

    $this->artisan('subscriptions:regenerate --dry-run')->assertSuccessful();

    expect(Subscription::count())->toBe($before);
});

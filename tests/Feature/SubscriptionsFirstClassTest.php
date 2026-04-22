<?php

use App\Models\Contact;
use App\Models\Contract;
use App\Models\RecurringRule;
use App\Models\Subscription;
use App\Support\SubscriptionSync;
use Livewire\Livewire;

it('auto-creates a Subscription when a new outflow RecurringRule is saved', function () {
    authedInHousehold();

    $rule = RecurringRule::create([
        'title' => 'Netflix', 'kind' => 'expense', 'amount' => -15.99, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;INTERVAL=1', 'dtstart' => now(), 'active' => true,
    ]);

    $sub = Subscription::where('recurring_rule_id', $rule->id)->first();
    expect($sub)->not->toBeNull()
        ->and($sub->name)->toBe('Netflix')
        ->and((float) $sub->monthly_cost_cached)->toBe(15.99);
});

it('does not auto-create for income rules (amount > 0)', function () {
    authedInHousehold();
    RecurringRule::create([
        'title' => 'Salary', 'kind' => 'income', 'amount' => 5000, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => true,
    ]);
    expect(Subscription::count())->toBe(0);
});

it('does not create a duplicate Subscription when the same rule is saved again', function () {
    authedInHousehold();
    $rule = RecurringRule::create([
        'title' => 'Spotify', 'kind' => 'expense', 'amount' => -10, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => true,
    ]);
    SubscriptionSync::fromRecurringRule($rule->fresh());
    SubscriptionSync::fromRecurringRule($rule->fresh());
    expect(Subscription::where('recurring_rule_id', $rule->id)->count())->toBe(1);
});

it('auto-links a newly-created Contract into an existing Subscription by counterparty', function () {
    authedInHousehold();
    $vendor = Contact::create(['kind' => 'organization', 'display_name' => 'Netflix']);
    $rule = RecurringRule::create([
        'title' => 'Netflix', 'kind' => 'expense', 'amount' => -15.99, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => true,
        'counterparty_contact_id' => $vendor->id,
    ]);
    $sub = Subscription::where('recurring_rule_id', $rule->id)->firstOrFail();
    expect($sub->contract_id)->toBeNull();

    $contract = Contract::create([
        'title' => 'Netflix Premium', 'kind' => 'subscription', 'state' => 'active',
        'cancellation_url' => 'https://netflix.com/cancel',
    ]);
    $contract->contacts()->attach($vendor->id, ['party_role' => 'counterparty']);
    SubscriptionSync::linkContract($contract);

    expect($sub->fresh()->contract_id)->toBe($contract->id);
});

it('page renders auto-created subscriptions', function () {
    authedInHousehold();
    RecurringRule::create([
        'title' => 'Disney+', 'kind' => 'expense', 'amount' => -8, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => true,
    ]);

    $this->get(route('fiscal.subscriptions'))
        ->assertOk()
        ->assertSee('Disney+')
        ->assertSee(__('New subscription'));
});

it('inspector edits an auto-created subscription', function () {
    authedInHousehold();
    $rule = RecurringRule::create([
        'title' => 'Basic', 'kind' => 'expense', 'amount' => -10, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => true,
    ]);
    $sub = Subscription::where('recurring_rule_id', $rule->id)->firstOrFail();

    Livewire::test('inspector.subscription-form', ['id' => $sub->id])
        ->assertSet('subscription_name', 'Basic')
        ->set('subscription_name', 'Renamed')
        ->call('save');

    expect($sub->fresh()->name)->toBe('Renamed');
});

it('subscriptions:backfill is idempotent and can seed rules that predated the observer', function () {
    authedInHousehold();
    RecurringRule::create([
        'title' => 'Old Rule A', 'kind' => 'expense', 'amount' => -20, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => true,
    ]);
    RecurringRule::create([
        'title' => 'Old Rule B', 'kind' => 'expense', 'amount' => -120, 'currency' => 'USD',
        'rrule' => 'FREQ=YEARLY', 'dtstart' => now(), 'active' => true,
    ]);
    expect(Subscription::count())->toBe(2);   // created by observer

    // Drop them and re-run the backfill — simulates the "rule existed before
    // the subscriptions table landed" scenario.
    Subscription::query()->delete();
    $this->artisan('subscriptions:backfill')
        ->expectsOutputToContain('Backfilled 2')
        ->assertSuccessful();
    expect(Subscription::count())->toBe(2);

    // Re-running does not duplicate (idempotent).
    $this->artisan('subscriptions:backfill')->assertSuccessful();
    expect(Subscription::count())->toBe(2);
});

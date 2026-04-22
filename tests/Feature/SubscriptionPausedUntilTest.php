<?php

use App\Models\RecurringRule;
use App\Models\Subscription;
use Livewire\Livewire;

it('persists paused_until via the inspector when state is paused', function () {
    authedInHousehold();
    $rule = RecurringRule::create([
        'title' => 'Netflix', 'kind' => 'expense', 'amount' => -15.99, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => true,
    ]);
    $sub = Subscription::where('recurring_rule_id', $rule->id)->firstOrFail();

    Livewire::test('inspector.subscription-form', ['id' => $sub->id])
        ->set('subscription_state', 'paused')
        ->set('subscription_paused_until', now()->addDays(30)->toDateString())
        ->call('save');

    $fresh = $sub->fresh();
    expect($fresh->state)->toBe('paused')
        ->and($fresh->paused_until?->toDateString())->toBe(now()->addDays(30)->toDateString());
});

it('clears paused_until when state transitions back to active', function () {
    authedInHousehold();
    $rule = RecurringRule::create([
        'title' => 'Spotify', 'kind' => 'expense', 'amount' => -10, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => true,
    ]);
    $sub = Subscription::where('recurring_rule_id', $rule->id)->firstOrFail();
    $sub->forceFill(['state' => 'paused', 'paused_until' => now()->addDays(7)])->save();

    Livewire::test('inspector.subscription-form', ['id' => $sub->id])
        ->set('subscription_state', 'active')
        ->call('save');

    expect($sub->fresh()->paused_until)->toBeNull();
});

it('artisan subscriptions:resume-due flips due rows to active', function () {
    authedInHousehold();
    $rule = RecurringRule::create([
        'title' => 'Sub', 'kind' => 'expense', 'amount' => -5, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => true,
    ]);
    $sub = Subscription::where('recurring_rule_id', $rule->id)->firstOrFail();
    // Paused until yesterday → should resume
    $sub->forceFill(['state' => 'paused', 'paused_until' => now()->subDay()])->save();

    $rule2 = RecurringRule::create([
        'title' => 'Later', 'kind' => 'expense', 'amount' => -5, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => now(), 'active' => true,
    ]);
    $sub2 = Subscription::where('recurring_rule_id', $rule2->id)->firstOrFail();
    // Paused until next week → stays paused
    $sub2->forceFill(['state' => 'paused', 'paused_until' => now()->addDays(7)])->save();

    $this->artisan('subscriptions:resume-due')
        ->expectsOutputToContain('Resumed 1')
        ->assertSuccessful();

    expect($sub->fresh()->state)->toBe('active')
        ->and($sub->fresh()->paused_until)->toBeNull()
        ->and($sub2->fresh()->state)->toBe('paused');
});

<?php

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\SavingsGoal;
use Livewire\Livewire;

it('page renders with empty state and a New goal button', function () {
    authedInHousehold();
    $this->get(route('fiscal.savings_goals'))
        ->assertOk()
        ->assertSee(__('No savings goals yet.'))
        ->assertSee(__('New goal'));
});

it('creates a savings goal via the inspector', function () {
    authedInHousehold();

    Livewire::test('inspector')
        ->call('openInspector', 'savings_goal')
        ->set('savings_name', 'Emergency fund')
        ->set('savings_target_amount', '10000')
        ->set('savings_saved_amount', '3500')
        ->set('savings_currency', 'USD')
        ->call('save');

    $g = SavingsGoal::where('name', 'Emergency fund')->firstOrFail();
    expect((float) $g->saved_amount)->toBe(3500.0)
        ->and(round($g->progressRatio(), 2))->toBe(0.35);
});

it('computes progress from linked account balance', function () {
    authedInHousehold();
    $acc = Account::create(['name' => 'HYSA', 'type' => 'savings', 'currency' => 'USD', 'is_active' => true]);
    AccountBalance::create(['account_id' => $acc->id, 'balance' => 4200, 'currency' => 'USD', 'as_of' => now()]);

    $g = SavingsGoal::forceCreate([
        'name' => 'House', 'target_amount' => 50000, 'starting_amount' => 1000,
        'saved_amount' => 0, 'account_id' => $acc->id, 'currency' => 'USD', 'state' => 'active',
    ]);

    expect($g->currentSaved())->toBe(3200.0);
});

it('edits a goal via the inspector', function () {
    authedInHousehold();
    $g = SavingsGoal::forceCreate([
        'name' => 'Draft', 'target_amount' => 100, 'starting_amount' => 0,
        'saved_amount' => 0, 'currency' => 'USD', 'state' => 'active',
    ]);

    Livewire::test('inspector')
        ->call('openInspector', 'savings_goal', $g->id)
        ->set('savings_saved_amount', '75')
        ->call('save');

    expect((float) $g->fresh()->saved_amount)->toBe(75.0);
});

it('deletes a goal via the inspector', function () {
    authedInHousehold();
    $g = SavingsGoal::forceCreate([
        'name' => 'x', 'target_amount' => 1, 'saved_amount' => 0,
        'starting_amount' => 0, 'currency' => 'USD', 'state' => 'active',
    ]);

    Livewire::test('inspector')
        ->call('openInspector', 'savings_goal', $g->id)
        ->call('deleteRecord');

    expect(SavingsGoal::where('id', $g->id)->exists())->toBeFalse();
});

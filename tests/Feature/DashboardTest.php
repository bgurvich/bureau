<?php

use App\Models\Account;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Transaction;
use Livewire\Livewire;

it('renders the dashboard for an authenticated user with a household', function () {
    authedInHousehold();

    $this->get('/')
        ->assertOk()
        ->assertSee('Bureau')
        ->assertSee('Money')
        ->assertSee('Commitments')
        ->assertSee('Attention')
        ->assertSee('Time tracker');
});

it('serves stub domain pages as 200 for an authenticated user', function () {
    authedInHousehold();

    foreach (['/accounts', '/transactions', '/tasks', '/meetings', '/contacts', '/contracts', '/documents', '/time/projects'] as $path) {
        $this->get($path)->assertOk();
    }
});

it('logs the user out on POST /logout', function () {
    authedInHousehold();
    $this->post('/logout')->assertRedirect('/login');
    expect(auth()->check())->toBeFalse();
});

it('money radar net worth reflects cleared transactions and transfers', function () {
    authedInHousehold();

    $account = Account::create([
        'type' => 'checking', 'name' => 'Main', 'currency' => 'USD',
        'opening_balance' => 1000, 'include_in_net_worth' => true, 'is_active' => true,
    ]);

    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->subDays(3)->toDateString(),
        'amount' => -250, 'currency' => 'USD', 'status' => 'cleared',
    ]);
    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->subDays(1)->toDateString(),
        'amount' => 100, 'currency' => 'USD', 'status' => 'pending',
    ]);

    // Net worth = 1000 + (-250) = 750. Pending $100 must NOT count.
    Livewire::test('money-radar')
        ->assertSet('netWorth', 750.0);
});

it('surfaces a 30-day projected net + end-balance forecast tile', function () {
    $user = authedInHousehold();

    $account = Account::create([
        'type' => 'checking', 'name' => 'Checking', 'currency' => 'USD',
        'opening_balance' => 1000, 'include_in_net_worth' => true, 'is_active' => true,
        'user_id' => $user->id,
    ]);

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Rent',
        'amount' => -200, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        'dtstart' => now()->subMonth()->toDateString(),
        'account_id' => $account->id,
    ]);
    RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => now()->addDays(5)->toDateString(),
        'amount' => -200, 'currency' => 'USD', 'status' => 'projected',
    ]);
    RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => now()->addDays(10)->toDateString(),
        'amount' => 500, 'currency' => 'USD', 'status' => 'projected',
    ]);

    $forecast = Livewire::test('money-radar')->get('forecast30');

    expect((float) $forecast['expense'])->toBe(-200.0)
        ->and((float) $forecast['income'])->toBe(500.0)
        ->and((float) $forecast['net'])->toBe(300.0)
        ->and((float) $forecast['end_balance'])->toBe(1300.0);
});

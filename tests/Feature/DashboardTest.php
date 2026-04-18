<?php

use App\Models\Account;
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
        'type' => 'bank', 'name' => 'Main', 'currency' => 'USD',
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

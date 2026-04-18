<?php

use App\Models\Account;
use App\Models\AssetValuation;
use App\Models\Contract;
use App\Models\Property;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

it('renders the finance overview page', function () {
    authedInHousehold();

    $this->get('/finance')
        ->assertOk()
        ->assertSee('Finance overview')
        ->assertSee('Household net worth');
});

it('sums income, expense, and net for the current month', function () {
    CarbonImmutable::setTestNow('2026-04-15');
    authedInHousehold();

    $account = Account::create([
        'type' => 'bank', 'name' => 'Main', 'currency' => 'USD',
        'opening_balance' => 1000, 'include_in_net_worth' => true,
    ]);

    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-04-05',
        'amount' => 2500, 'currency' => 'USD', 'status' => 'cleared',
    ]);
    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-04-10',
        'amount' => -300, 'currency' => 'USD', 'status' => 'cleared',
    ]);
    // March — must NOT count
    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-03-28',
        'amount' => -50, 'currency' => 'USD', 'status' => 'cleared',
    ]);

    $this->get('/finance')
        ->assertSee('2,500.00')  // income
        ->assertSee('300.00')    // expense
        ->assertSee('2,200.00'); // net

    CarbonImmutable::setTestNow();
});

it('shows active subscription burn and trial count', function () {
    authedInHousehold();

    Contract::create([
        'kind' => 'subscription', 'title' => 'Netflix',
        'state' => 'active',
        'monthly_cost_amount' => 15.49, 'monthly_cost_currency' => 'USD',
    ]);
    Contract::create([
        'kind' => 'subscription', 'title' => 'Gym',
        'state' => 'active',
        'monthly_cost_amount' => 35, 'monthly_cost_currency' => 'USD',
        'trial_ends_on' => now()->addDays(5)->toDateString(),
    ]);

    $this->get('/finance')
        ->assertSee('50.49')       // monthly burn
        ->assertSee('Trials to cancel');
});

it('includes latest asset valuations in the net worth total', function () {
    authedInHousehold();

    $home = Property::create([
        'kind' => 'home', 'name' => 'House', 'purchase_price' => 400000, 'purchase_currency' => 'USD',
    ]);
    AssetValuation::create([
        'valuable_type' => Property::class,
        'valuable_id' => $home->id,
        'as_of' => now()->subMonths(6)->toDateString(),
        'value' => 450000, 'currency' => 'USD',
        'method' => 'estimate', 'source' => 'Zillow',
    ]);
    AssetValuation::create([
        'valuable_type' => Property::class,
        'valuable_id' => $home->id,
        'as_of' => now()->subMonth()->toDateString(),
        'value' => 475000, 'currency' => 'USD',
        'method' => 'estimate', 'source' => 'Zillow',
    ]);

    $this->get('/finance')
        ->assertOk()
        ->assertSee('475,000')    // latest valuation wins
        ->assertDontSee('450,000');
});

it('groups net worth by account kind', function () {
    authedInHousehold();

    Account::create([
        'type' => 'bank', 'name' => 'Checking', 'currency' => 'USD',
        'opening_balance' => 5000, 'include_in_net_worth' => true,
    ]);
    Account::create([
        'type' => 'credit', 'name' => 'Card', 'currency' => 'USD',
        'opening_balance' => -800, 'include_in_net_worth' => true,
    ]);

    $this->get('/finance')
        ->assertSee('Bank')
        ->assertSee('Credit')
        ->assertSee('5,000.00')
        ->assertSee('-800.00');
});

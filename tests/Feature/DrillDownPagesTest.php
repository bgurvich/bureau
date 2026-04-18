<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Models\User;

function seedForDrillDown(): User
{
    $user = authedInHousehold();

    $account = Account::create([
        'type' => 'bank', 'name' => 'Chase Checking',
        'institution' => 'Chase', 'currency' => 'USD', 'opening_balance' => 500,
    ]);

    $cat = Category::create(['kind' => 'expense', 'slug' => 'food', 'name' => 'Food']);

    Transaction::create([
        'account_id' => $account->id,
        'category_id' => $cat->id,
        'occurred_on' => now()->subDays(5)->toDateString(),
        'amount' => -42.00,
        'currency' => 'USD',
        'description' => 'Groceries',
        'status' => 'cleared',
    ]);

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Rent',
        'amount' => -2200, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        'dtstart' => now()->subMonths(6)->startOfMonth()->toDateString(),
        'account_id' => $account->id,
    ]);

    RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => now()->addDays(10)->toDateString(),
        'amount' => -2200,
        'currency' => 'USD',
        'status' => 'projected',
    ]);

    return $user;
}

it('renders the Accounts drill-down', function () {
    seedForDrillDown();

    $this->get('/accounts')
        ->assertOk()
        ->assertSee('Chase Checking')
        ->assertSee('Net worth');
});

it('renders the Transactions drill-down with a matching row', function () {
    seedForDrillDown();

    $this->get('/transactions?q=Groceries')
        ->assertOk()
        ->assertSee('Groceries')
        ->assertSee('Chase Checking');
});

it('renders the Bills & Income drill-down with upcoming rule', function () {
    seedForDrillDown();

    $this->get('/bills')
        ->assertOk()
        ->assertSee('Rent')
        ->assertSee('Upcoming');
});

it('renders the Bookkeeper page', function () {
    seedForDrillDown();

    $this->get('/bookkeeper')
        ->assertOk()
        ->assertSee('Bookkeeper export')
        ->assertSee('Period lock');
});

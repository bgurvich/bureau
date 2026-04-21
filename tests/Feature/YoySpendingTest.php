<?php

use App\Models\Account;
use App\Models\Transaction;

it('renders a YoY spending table with monthly rows', function () {
    authedInHousehold();

    $this->get(route('fiscal.yoy'))
        ->assertOk()
        ->assertSee('Year over year')
        ->assertSeeText('Jan')
        ->assertSeeText('Dec');
});

it('sums monthly outflows for current and prior year', function () {
    authedInHousehold();
    $acc = Account::create(['name' => 'C', 'type' => 'checking', 'currency' => 'USD']);

    $thisYear = (int) now()->year;
    $priorYear = $thisYear - 1;
    // March this year: -100
    Transaction::create([
        'account_id' => $acc->id, 'amount' => -100, 'currency' => 'USD',
        'occurred_on' => "$thisYear-03-15", 'description' => 'a', 'status' => 'cleared',
    ]);
    // March prior year: -50
    Transaction::create([
        'account_id' => $acc->id, 'amount' => -50, 'currency' => 'USD',
        'occurred_on' => "$priorYear-03-10", 'description' => 'b', 'status' => 'cleared',
    ]);
    // Income row must be ignored
    Transaction::create([
        'account_id' => $acc->id, 'amount' => 5000, 'currency' => 'USD',
        'occurred_on' => "$thisYear-03-01", 'description' => 'salary', 'status' => 'cleared',
    ]);

    $this->get(route('fiscal.yoy'))
        ->assertOk()
        ->assertSeeText('100.00')
        ->assertSeeText('50.00');
});

it('switches to a prior year via the ?year query string', function () {
    authedInHousehold();
    $this->get(route('fiscal.yoy').'?year=2020')
        ->assertOk()
        ->assertSee('2020')
        ->assertSee('2019');
});

<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Transaction;
use Livewire\Livewire;

it('groups transactions into one row per month with count + net', function () {
    authedInHousehold();

    $account = Account::create([
        'type' => 'checking', 'name' => 'Everyday',
        'currency' => 'USD', 'opening_balance' => 0,
    ]);

    // Two months of data.
    Transaction::create(['account_id' => $account->id, 'occurred_on' => '2026-03-05', 'amount' => 1000, 'currency' => 'USD', 'description' => 'Payroll', 'status' => 'cleared']);
    Transaction::create(['account_id' => $account->id, 'occurred_on' => '2026-03-10', 'amount' => -50, 'currency' => 'USD', 'description' => 'Lunch', 'status' => 'cleared']);
    Transaction::create(['account_id' => $account->id, 'occurred_on' => '2026-04-05', 'amount' => 1200, 'currency' => 'USD', 'description' => 'Payroll', 'status' => 'cleared']);
    Transaction::create(['account_id' => $account->id, 'occurred_on' => '2026-04-08', 'amount' => -200, 'currency' => 'USD', 'description' => 'Groceries', 'status' => 'cleared']);

    $months = Livewire::test('transactions-months')->instance()->months;

    expect($months)->toHaveCount(2);
    $mar = $months->firstWhere('ym', '2026-03');
    $apr = $months->firstWhere('ym', '2026-04');

    expect($mar['count'])->toBe(2)
        ->and($mar['credits'])->toBe(1000.0)
        ->and($mar['debits'])->toBe(-50.0)
        ->and($mar['net'])->toBe(950.0)
        ->and($apr['count'])->toBe(2)
        ->and($apr['net'])->toBe(1000.0);
});

it('narrows months to the selected account', function () {
    authedInHousehold();

    $a = Account::create(['type' => 'checking', 'name' => 'A', 'currency' => 'USD', 'opening_balance' => 0]);
    $b = Account::create(['type' => 'savings', 'name' => 'B', 'currency' => 'USD', 'opening_balance' => 0]);

    Transaction::create(['account_id' => $a->id, 'occurred_on' => '2026-03-05', 'amount' => 500, 'currency' => 'USD', 'status' => 'cleared']);
    Transaction::create(['account_id' => $b->id, 'occurred_on' => '2026-04-05', 'amount' => 700, 'currency' => 'USD', 'status' => 'cleared']);

    $months = Livewire::test('transactions-months')
        ->set('accountId', (string) $a->id)
        ->instance()
        ->months;

    expect($months->pluck('ym')->all())->toBe(['2026-03']);
});

it('sorts rows by the selected column and toggles direction on re-click', function () {
    authedInHousehold();

    $account = Account::create(['type' => 'checking', 'name' => 'Everyday', 'currency' => 'USD', 'opening_balance' => 0]);
    // Biggest month = Feb (+1500 net), quietest = Mar (1 txn), middle = Apr.
    Transaction::create(['account_id' => $account->id, 'occurred_on' => '2026-02-10', 'amount' => 1500, 'currency' => 'USD', 'status' => 'cleared']);
    Transaction::create(['account_id' => $account->id, 'occurred_on' => '2026-02-20', 'amount' => 500, 'currency' => 'USD', 'status' => 'cleared']);
    Transaction::create(['account_id' => $account->id, 'occurred_on' => '2026-03-05', 'amount' => -50, 'currency' => 'USD', 'status' => 'cleared']);
    Transaction::create(['account_id' => $account->id, 'occurred_on' => '2026-04-05', 'amount' => 300, 'currency' => 'USD', 'status' => 'cleared']);
    Transaction::create(['account_id' => $account->id, 'occurred_on' => '2026-04-15', 'amount' => -100, 'currency' => 'USD', 'status' => 'cleared']);

    $component = Livewire::test('transactions-months');

    // Default: month desc → Apr, Mar, Feb.
    expect($component->instance()->months->pluck('ym')->all())->toBe(['2026-04', '2026-03', '2026-02']);

    // Click Net header → desc net (biggest first = Feb).
    $component->call('sort', 'net');
    expect($component->get('sortBy'))->toBe('net')
        ->and($component->get('sortDir'))->toBe('desc')
        ->and($component->instance()->months->pluck('ym')->first())->toBe('2026-02');

    // Re-click Net → asc (smallest first).
    $component->call('sort', 'net');
    expect($component->get('sortDir'))->toBe('asc')
        ->and($component->instance()->months->pluck('ym')->first())->toBe('2026-03');

    // Click Count → asc (quietest month first).
    $component->call('sort', 'count');
    expect($component->get('sortBy'))->toBe('count')
        ->and($component->get('sortDir'))->toBe('asc')
        ->and($component->instance()->months->pluck('ym')->first())->toBe('2026-03');
});

it('rejects unknown sort columns silently', function () {
    authedInHousehold();

    Livewire::test('transactions-months')
        ->call('sort', 'attempted_injection')
        ->assertSet('sortBy', 'ym')
        ->assertSet('sortDir', 'desc');
});

it('renders the Months tab inside the Ledger hub', function () {
    authedInHousehold();

    $account = Account::create([
        'type' => 'checking', 'name' => 'Everyday',
        'currency' => 'USD', 'opening_balance' => 0,
    ]);
    Transaction::create([
        'account_id' => $account->id, 'occurred_on' => '2026-03-05',
        'amount' => 100, 'currency' => 'USD', 'status' => 'cleared',
    ]);

    $this->get(route('fiscal.ledger', ['tab' => 'months']))
        ->assertOk()
        ->assertSee(__('Month'))
        ->assertSee(__('Net'))
        ->assertSee('March 2026');
});

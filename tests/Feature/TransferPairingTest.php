<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Support\TransferPairing;

it('pairs a debit and credit across accounts with matching magnitude and date', function () {
    $user = authedInHousehold();
    $checking = Account::create(['type' => 'bank', 'name' => 'Checking', 'currency' => 'USD', 'opening_balance' => 0]);
    $savings = Account::create(['type' => 'bank', 'name' => 'Savings', 'currency' => 'USD', 'opening_balance' => 0]);

    $debit = Transaction::create([
        'account_id' => $checking->id, 'occurred_on' => '2026-03-05',
        'amount' => -500.00, 'currency' => 'USD', 'description' => 'Transfer to savings', 'status' => 'cleared',
    ]);
    $credit = Transaction::create([
        'account_id' => $savings->id, 'occurred_on' => '2026-03-06',
        'amount' => 500.00, 'currency' => 'USD', 'description' => 'Transfer from checking', 'status' => 'cleared',
    ]);

    $pairs = app(TransferPairing::class)->pair($user->defaultHousehold);
    expect($pairs)->toBe(1);

    $t = Transfer::firstOrFail();
    expect($t->from_account_id)->toBe($checking->id)
        ->and($t->to_account_id)->toBe($savings->id)
        ->and($t->from_transaction_id)->toBe($debit->id)
        ->and($t->to_transaction_id)->toBe($credit->id);
});

it('does not pair when two credit candidates exist in the window (ambiguous)', function () {
    $user = authedInHousehold();
    $checking = Account::create(['type' => 'bank', 'name' => 'Checking', 'currency' => 'USD', 'opening_balance' => 0]);
    $savings = Account::create(['type' => 'bank', 'name' => 'Savings', 'currency' => 'USD', 'opening_balance' => 0]);
    $other = Account::create(['type' => 'bank', 'name' => 'Other', 'currency' => 'USD', 'opening_balance' => 0]);

    Transaction::create(['account_id' => $checking->id, 'occurred_on' => '2026-03-05',
        'amount' => -500.00, 'currency' => 'USD', 'description' => 'debit', 'status' => 'cleared']);
    Transaction::create(['account_id' => $savings->id, 'occurred_on' => '2026-03-06',
        'amount' => 500.00, 'currency' => 'USD', 'description' => 'credit 1', 'status' => 'cleared']);
    Transaction::create(['account_id' => $other->id, 'occurred_on' => '2026-03-07',
        'amount' => 500.00, 'currency' => 'USD', 'description' => 'credit 2', 'status' => 'cleared']);

    expect(app(TransferPairing::class)->pair($user->defaultHousehold))->toBe(0);
});

it('skips transactions already part of a Transfer', function () {
    $user = authedInHousehold();
    $checking = Account::create(['type' => 'bank', 'name' => 'Checking', 'currency' => 'USD', 'opening_balance' => 0]);
    $savings = Account::create(['type' => 'bank', 'name' => 'Savings', 'currency' => 'USD', 'opening_balance' => 0]);

    $debit = Transaction::create(['account_id' => $checking->id, 'occurred_on' => '2026-03-05',
        'amount' => -500.00, 'currency' => 'USD', 'description' => 'd', 'status' => 'cleared']);
    $credit = Transaction::create(['account_id' => $savings->id, 'occurred_on' => '2026-03-06',
        'amount' => 500.00, 'currency' => 'USD', 'description' => 'c', 'status' => 'cleared']);

    app(TransferPairing::class)->pair($user->defaultHousehold);
    expect(Transfer::count())->toBe(1);

    // Running again does not double-create
    $second = app(TransferPairing::class)->pair($user->defaultHousehold);
    expect($second)->toBe(0)
        ->and(Transfer::count())->toBe(1);
});

it('ignores pairs outside the ±3d date window', function () {
    $user = authedInHousehold();
    $checking = Account::create(['type' => 'bank', 'name' => 'Checking', 'currency' => 'USD', 'opening_balance' => 0]);
    $savings = Account::create(['type' => 'bank', 'name' => 'Savings', 'currency' => 'USD', 'opening_balance' => 0]);

    Transaction::create(['account_id' => $checking->id, 'occurred_on' => '2026-03-01',
        'amount' => -500.00, 'currency' => 'USD', 'description' => 'd', 'status' => 'cleared']);
    Transaction::create(['account_id' => $savings->id, 'occurred_on' => '2026-03-10',   // 9 days later
        'amount' => 500.00, 'currency' => 'USD', 'description' => 'c', 'status' => 'cleared']);

    expect(app(TransferPairing::class)->pair($user->defaultHousehold))->toBe(0);
});

it('only pairs same-currency transactions in v1', function () {
    $user = authedInHousehold();
    $checking = Account::create(['type' => 'bank', 'name' => 'USD Checking', 'currency' => 'USD', 'opening_balance' => 0]);
    $euro = Account::create(['type' => 'bank', 'name' => 'EUR Savings', 'currency' => 'EUR', 'opening_balance' => 0]);

    Transaction::create(['account_id' => $checking->id, 'occurred_on' => '2026-03-05',
        'amount' => -500.00, 'currency' => 'USD', 'description' => 'd', 'status' => 'cleared']);
    Transaction::create(['account_id' => $euro->id, 'occurred_on' => '2026-03-06',
        'amount' => 500.00, 'currency' => 'EUR', 'description' => 'c', 'status' => 'cleared']);

    expect(app(TransferPairing::class)->pair($user->defaultHousehold))->toBe(0);
});

it('artisan transfers:pair runs the pairing and reports the count', function () {
    $user = authedInHousehold();
    $a = Account::create(['type' => 'bank', 'name' => 'A', 'currency' => 'USD', 'opening_balance' => 0]);
    $b = Account::create(['type' => 'bank', 'name' => 'B', 'currency' => 'USD', 'opening_balance' => 0]);
    Transaction::create(['account_id' => $a->id, 'occurred_on' => '2026-03-05',
        'amount' => -100.00, 'currency' => 'USD', 'description' => 'd', 'status' => 'cleared']);
    Transaction::create(['account_id' => $b->id, 'occurred_on' => '2026-03-06',
        'amount' => 100.00, 'currency' => 'USD', 'description' => 'c', 'status' => 'cleared']);

    $this->artisan('transfers:pair')->assertExitCode(0);
    expect(Transfer::count())->toBe(1);
});

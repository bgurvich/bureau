<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\Transfer;
use Livewire\Livewire;

it('creates a transfer with both mirror transactions when no existing rows are picked', function () {
    authedInHousehold();
    $from = Account::create(['type' => 'checking', 'name' => 'Checking', 'currency' => 'USD', 'opening_balance' => 0]);
    $to = Account::create(['type' => 'savings', 'name' => 'Savings', 'currency' => 'USD', 'opening_balance' => 0]);

    Livewire::test('inspector.transfer-form')
        ->set('transfer_occurred_on', '2026-04-15')
        ->set('transfer_from_account_id', $from->id)
        ->set('transfer_to_account_id', $to->id)
        ->set('transfer_amount', '250.00')
        ->set('transfer_currency', 'USD')
        ->call('save')
        ->assertHasNoErrors();

    expect(Transfer::count())->toBe(1);
    expect(Transaction::count())->toBe(2);

    $transfer = Transfer::firstOrFail();
    expect($transfer->from_account_id)->toBe($from->id)
        ->and($transfer->to_account_id)->toBe($to->id)
        ->and((float) $transfer->from_amount)->toBe(-250.0)
        ->and((float) $transfer->to_amount)->toBe(250.0);
});

it('links existing unpaired transactions instead of duplicating them', function () {
    authedInHousehold();
    $from = Account::create(['type' => 'checking', 'name' => 'Checking', 'currency' => 'USD', 'opening_balance' => 0]);
    $to = Account::create(['type' => 'savings', 'name' => 'Savings', 'currency' => 'USD', 'opening_balance' => 0]);

    $debit = Transaction::create([
        'account_id' => $from->id, 'occurred_on' => '2026-04-15',
        'amount' => -500.00, 'currency' => 'USD', 'description' => 'Already imported debit',
        'status' => 'cleared',
    ]);
    $credit = Transaction::create([
        'account_id' => $to->id, 'occurred_on' => '2026-04-15',
        'amount' => 500.00, 'currency' => 'USD', 'description' => 'Already imported credit',
        'status' => 'cleared',
    ]);

    Livewire::test('inspector.transfer-form')
        ->set('transfer_occurred_on', '2026-04-15')
        ->set('transfer_from_account_id', $from->id)
        ->set('transfer_to_account_id', $to->id)
        ->set('transfer_amount', '500.00')
        ->set('transfer_currency', 'USD')
        ->set('transfer_from_transaction_id', $debit->id)
        ->set('transfer_to_transaction_id', $credit->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::count())->toBe(2);
    $transfer = Transfer::firstOrFail();
    expect($transfer->from_transaction_id)->toBe($debit->id)
        ->and($transfer->to_transaction_id)->toBe($credit->id);
});

it('creates the missing side when only one existing transaction is linked', function () {
    authedInHousehold();
    $from = Account::create(['type' => 'checking', 'name' => 'Checking', 'currency' => 'USD', 'opening_balance' => 0]);
    $to = Account::create(['type' => 'savings', 'name' => 'Savings', 'currency' => 'USD', 'opening_balance' => 0]);
    $debit = Transaction::create([
        'account_id' => $from->id, 'occurred_on' => '2026-04-15',
        'amount' => -100.00, 'currency' => 'USD', 'description' => 'Imported debit',
        'status' => 'cleared',
    ]);

    Livewire::test('inspector.transfer-form')
        ->set('transfer_occurred_on', '2026-04-15')
        ->set('transfer_from_account_id', $from->id)
        ->set('transfer_to_account_id', $to->id)
        ->set('transfer_amount', '100.00')
        ->set('transfer_currency', 'USD')
        ->set('transfer_from_transaction_id', $debit->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::count())->toBe(2);
    $transfer = Transfer::firstOrFail();
    expect($transfer->from_transaction_id)->toBe($debit->id)
        ->and($transfer->to_transaction_id)->not->toBe($debit->id);
});

it('rejects linking a transaction that is already part of another transfer', function () {
    authedInHousehold();
    $from = Account::create(['type' => 'checking', 'name' => 'Checking', 'currency' => 'USD', 'opening_balance' => 0]);
    $to = Account::create(['type' => 'savings', 'name' => 'Savings', 'currency' => 'USD', 'opening_balance' => 0]);
    $debit = Transaction::create([
        'account_id' => $from->id, 'occurred_on' => '2026-04-15',
        'amount' => -50.00, 'currency' => 'USD', 'description' => 'Debit', 'status' => 'cleared',
    ]);
    $credit = Transaction::create([
        'account_id' => $to->id, 'occurred_on' => '2026-04-15',
        'amount' => 50.00, 'currency' => 'USD', 'description' => 'Credit', 'status' => 'cleared',
    ]);
    Transfer::create([
        'occurred_on' => '2026-04-15',
        'from_account_id' => $from->id, 'from_amount' => -50, 'from_currency' => 'USD',
        'from_transaction_id' => $debit->id,
        'to_account_id' => $to->id, 'to_amount' => 50, 'to_currency' => 'USD',
        'to_transaction_id' => $credit->id,
        'status' => 'cleared',
    ]);

    Livewire::test('inspector.transfer-form')
        ->set('transfer_occurred_on', '2026-04-15')
        ->set('transfer_from_account_id', $from->id)
        ->set('transfer_to_account_id', $to->id)
        ->set('transfer_amount', '50.00')
        ->set('transfer_currency', 'USD')
        ->set('transfer_from_transaction_id', $debit->id)
        ->call('save')
        ->assertHasErrors(['transfer_from_transaction_id']);

    expect(Transfer::count())->toBe(1);
});

it('rejects when from and to accounts are the same', function () {
    authedInHousehold();
    $acct = Account::create(['type' => 'checking', 'name' => 'Checking', 'currency' => 'USD', 'opening_balance' => 0]);

    Livewire::test('inspector.transfer-form')
        ->set('transfer_occurred_on', '2026-04-15')
        ->set('transfer_from_account_id', $acct->id)
        ->set('transfer_to_account_id', $acct->id)
        ->set('transfer_amount', '10')
        ->set('transfer_currency', 'USD')
        ->call('save')
        ->assertHasErrors(['transfer_from_account_id']);
});

<?php

use App\Exceptions\PeriodLockedException;
use App\Models\Account;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Support\CurrentHousehold;

function setupForLock(): array
{
    $user = authedInHousehold();
    $account = Account::create([
        'type' => 'checking', 'name' => 'Chase', 'institution' => 'Chase',
        'currency' => 'USD', 'opening_balance' => 0,
    ]);

    return [CurrentHousehold::get(), $user, $account];
}

it('rejects a transaction dated on or before the lock', function () {
    [, , $account] = setupForLock();

    PeriodLock::create([
        'locked_through' => '2026-03-31',
        'reason' => 'Filed 2025 taxes',
        'locked_at' => now(),
    ]);

    $make = fn () => Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-03-15',
        'amount' => -42.00,
        'currency' => 'USD',
        'status' => 'cleared',
        'description' => 'Inside locked period',
    ]);

    expect($make)->toThrow(PeriodLockedException::class);
});

it('rejects transaction on the exact lock day', function () {
    [, , $account] = setupForLock();

    PeriodLock::create([
        'locked_through' => '2026-03-31',
        'locked_at' => now(),
    ]);

    expect(fn () => Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-03-31',
        'amount' => -10,
        'currency' => 'USD',
        'status' => 'cleared',
    ]))->toThrow(PeriodLockedException::class);
});

it('allows a transaction dated after the lock', function () {
    [, , $account] = setupForLock();

    PeriodLock::create([
        'locked_through' => '2026-03-31',
        'locked_at' => now(),
    ]);

    $t = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-04-01',
        'amount' => -10,
        'currency' => 'USD',
        'status' => 'cleared',
    ]);

    expect($t->exists)->toBeTrue();
});

it('blocks deleting a transaction inside the lock', function () {
    [, , $account] = setupForLock();

    $t = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-02-10',
        'amount' => -50,
        'currency' => 'USD',
        'status' => 'cleared',
    ]);

    PeriodLock::create([
        'locked_through' => '2026-03-31',
        'locked_at' => now(),
    ]);

    expect(fn () => $t->delete())->toThrow(PeriodLockedException::class);
});

it('releases the constraint when the lock is unlocked', function () {
    [, , $account] = setupForLock();

    $lock = PeriodLock::create([
        'locked_through' => '2026-03-31',
        'locked_at' => now(),
    ]);

    $lock->update(['unlocked_at' => now()]);

    $t = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-03-10',
        'amount' => -10,
        'currency' => 'USD',
        'status' => 'cleared',
    ]);

    expect($t->exists)->toBeTrue();
});

it('applies to transfers as well', function () {
    [, , $account] = setupForLock();
    $savings = Account::create([
        'type' => 'savings', 'name' => 'Savings', 'currency' => 'USD', 'opening_balance' => 0,
    ]);

    PeriodLock::create(['locked_through' => '2026-03-31', 'locked_at' => now()]);

    expect(fn () => Transfer::create([
        'from_account_id' => $account->id, 'from_amount' => 100, 'from_currency' => 'USD',
        'to_account_id' => $savings->id, 'to_amount' => 100, 'to_currency' => 'USD',
        'occurred_on' => '2026-03-15',
        'status' => 'cleared',
    ]))->toThrow(PeriodLockedException::class);
});

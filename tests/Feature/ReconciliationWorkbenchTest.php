<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

function setupForWorkbench(): Account
{
    authedInHousehold();

    return Account::create([
        'type' => 'bank', 'name' => 'Chase', 'currency' => 'USD', 'opening_balance' => 0,
    ]);
}

it('renders an empty-state when nothing needs reconciling', function () {
    authedInHousehold();

    $this->get('/reconcile')
        ->assertOk()
        ->assertSee(__('Reconciliation workbench'))
        ->assertSee(__('Nothing to reconcile. Everything is clean.'));
});

it('surfaces an overdue unmatched projection', function () {
    CarbonImmutable::setTestNow('2026-04-20');
    $account = setupForWorkbench();

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Overdue rent',
        'amount' => -2000, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        'dtstart' => '2026-04-01',
        'account_id' => $account->id,
    ]);
    RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => '2026-04-10',  // 10 days overdue
        'issued_on' => '2026-04-01',
        'amount' => -2000, 'currency' => 'USD', 'status' => 'overdue',
        'autopay' => false,
    ]);

    Livewire::test('reconciliation-workbench')
        ->assertSee('Overdue rent')
        ->assertSee(__('Mark paid'));

    CarbonImmutable::setTestNow();
});

it('surfaces stale pending transactions and can flip them to cleared', function () {
    CarbonImmutable::setTestNow('2026-04-20');
    $account = setupForWorkbench();

    $t = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-04-01', // 19 days ago
        'amount' => -42.50,
        'currency' => 'USD',
        'status' => 'pending',
        'description' => 'Old pending entry',
    ]);

    Livewire::test('reconciliation-workbench')
        ->assertSee('Old pending entry')
        ->call('markCleared', $t->id);

    expect($t->fresh()->status)->toBe('cleared');

    CarbonImmutable::setTestNow();
});

it('surfaces uncategorised cleared transactions', function () {
    $account = setupForWorkbench();

    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -15.99,
        'currency' => 'USD',
        'status' => 'cleared',
        'description' => 'Mystery bank row',
        // no category_id
    ]);

    Livewire::test('reconciliation-workbench')
        ->assertSee('Mystery bank row')
        ->assertSee(__('Categorise'));
});

it('counts total across all four orphan classes', function () {
    CarbonImmutable::setTestNow('2026-04-20');
    $account = setupForWorkbench();

    Transaction::create([
        'account_id' => $account->id, 'occurred_on' => '2026-04-01',
        'amount' => -10, 'currency' => 'USD', 'status' => 'pending',
    ]);
    Transaction::create([
        'account_id' => $account->id, 'occurred_on' => now()->toDateString(),
        'amount' => -20, 'currency' => 'USD', 'status' => 'cleared',
        // uncategorised
    ]);

    $c = Livewire::test('reconciliation-workbench');
    $counts = $c->get('counts');

    expect($counts['pending'])->toBe(1)
        ->and($counts['uncategorised'])->toBe(1)
        ->and($counts['total'])->toBeGreaterThanOrEqual(2);

    CarbonImmutable::setTestNow();
});

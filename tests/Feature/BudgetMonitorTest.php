<?php

use App\Models\Account;
use App\Models\BudgetCap;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\BudgetMonitor;
use Livewire\Livewire;

function budAcc(): Account
{
    return Account::create(['name' => 'C', 'type' => 'checking', 'currency' => 'USD']);
}

function budCat(string $name): Category
{
    return Category::create(['name' => $name, 'slug' => strtolower($name), 'kind' => 'expense']);
}

it('returns ok under 80% utilization', function () {
    authedInHousehold();
    $cat = budCat('Groceries');
    BudgetCap::create(['category_id' => $cat->id, 'monthly_cap' => 500, 'currency' => 'USD', 'active' => true]);

    $acc = budAcc();
    Transaction::create([
        'account_id' => $acc->id, 'amount' => -100, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'grocery', 'status' => 'cleared',
        'category_id' => $cat->id,
    ]);

    $row = BudgetMonitor::currentMonthStatuses()->first();
    expect($row->state)->toBe(BudgetMonitor::OK)
        ->and($row->spent)->toBe(100.0)
        ->and(round($row->ratio, 2))->toBe(0.2);
});

it('flags warning at 80% and over at 100%', function () {
    authedInHousehold();
    $warn = budCat('Dining');
    $over = budCat('Coffee');
    BudgetCap::create(['category_id' => $warn->id, 'monthly_cap' => 100, 'currency' => 'USD']);
    BudgetCap::create(['category_id' => $over->id, 'monthly_cap' => 50, 'currency' => 'USD']);

    $acc = budAcc();
    Transaction::create(['account_id' => $acc->id, 'amount' => -85, 'currency' => 'USD', 'occurred_on' => now(), 'description' => 'd', 'status' => 'cleared', 'category_id' => $warn->id]);
    Transaction::create(['account_id' => $acc->id, 'amount' => -60, 'currency' => 'USD', 'occurred_on' => now(), 'description' => 'c', 'status' => 'cleared', 'category_id' => $over->id]);

    $states = BudgetMonitor::currentMonthStatuses()->pluck('state')->all();
    expect($states)->toContain(BudgetMonitor::WARNING)
        ->and($states)->toContain(BudgetMonitor::OVER);
    expect(BudgetMonitor::currentMonthWarningCount())->toBe(2);
});

it('attention radar surfaces envelopes at risk', function () {
    authedInHousehold();
    $cat = budCat('Utilities');
    BudgetCap::create(['category_id' => $cat->id, 'monthly_cap' => 100, 'currency' => 'USD']);
    $acc = budAcc();
    Transaction::create(['account_id' => $acc->id, 'amount' => -95, 'currency' => 'USD', 'occurred_on' => now(), 'description' => 'u', 'status' => 'cleared', 'category_id' => $cat->id]);

    Livewire::test('attention-radar')
        ->assertSet('budgetEnvelopesAtRisk', 1)
        ->assertSee('Envelopes');
});

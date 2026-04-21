<?php

use App\Models\Account;
use App\Models\BudgetCap;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\BudgetAutoSuggester;
use Livewire\Livewire;

function sugAcc(): Account
{
    return Account::create(['name' => 'C', 'type' => 'checking', 'currency' => 'USD']);
}

function sugCat(string $name): Category
{
    return Category::create(['name' => $name, 'slug' => strtolower($name), 'kind' => 'expense']);
}

function sugTxn(Account $a, Category $c, float $amount, string $date): Transaction
{
    return Transaction::create([
        'account_id' => $a->id, 'category_id' => $c->id,
        'amount' => -abs($amount), 'currency' => 'USD',
        'occurred_on' => $date, 'description' => 'x', 'status' => 'cleared',
    ]);
}

it('suggests the 75th percentile of monthly spend over the lookback window', function () {
    authedInHousehold();
    $cat = sugCat('Groceries');
    $acc = sugAcc();
    // Spend in each of the last 5 full months: 100, 200, 300, 400, 500.
    for ($i = 1; $i <= 5; $i++) {
        sugTxn($acc, $cat, 100 * $i, now()->subMonths($i)->startOfMonth()->toDateString());
    }

    $sugs = (new BudgetAutoSuggester)->suggestions();
    expect($sugs)->toHaveCount(1);
    $row = $sugs->first();
    expect($row->samples)->toBe(5)
        ->and(round($row->p75))->toBe(400.0);
});

it('skips categories with fewer than minMonths samples', function () {
    authedInHousehold();
    $cat = sugCat('Rare');
    $acc = sugAcc();
    sugTxn($acc, $cat, 50, now()->subMonth()->startOfMonth()->toDateString());
    sugTxn($acc, $cat, 50, now()->subMonths(2)->startOfMonth()->toDateString());

    expect((new BudgetAutoSuggester)->suggestions())->toHaveCount(0);
});

it('applySuggestion creates a cap rounded up to the nearest 10', function () {
    authedInHousehold();
    $cat = sugCat('Coffee');
    $acc = sugAcc();
    for ($i = 1; $i <= 4; $i++) {
        sugTxn($acc, $cat, 42 + $i, now()->subMonths($i)->startOfMonth()->toDateString());
    }

    Livewire::test('budgets-index')
        ->call('toggleSuggestions')
        ->call('applySuggestion', $cat->id);

    $cap = BudgetCap::where('category_id', $cat->id)->first();
    expect($cap)->not->toBeNull()
        // p75 of [43, 44, 45, 46] ≈ 45.25 → ceil to nearest 10 = 50
        ->and((float) $cap->monthly_cap)->toBe(50.0);
});

it('does not overwrite an existing cap', function () {
    authedInHousehold();
    $cat = sugCat('Dining');
    $acc = sugAcc();
    for ($i = 1; $i <= 4; $i++) {
        sugTxn($acc, $cat, 100, now()->subMonths($i)->startOfMonth()->toDateString());
    }
    BudgetCap::forceCreate(['category_id' => $cat->id, 'monthly_cap' => 30, 'currency' => 'USD', 'active' => true]);

    Livewire::test('budgets-index')->call('applySuggestion', $cat->id);

    expect(BudgetCap::where('category_id', $cat->id)->count())->toBe(1)
        ->and((float) BudgetCap::where('category_id', $cat->id)->value('monthly_cap'))->toBe(30.0);
});

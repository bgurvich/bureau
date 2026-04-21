<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\SpendingAnomalyDetector;
use Livewire\Livewire;

function anomAcc(): Account
{
    return Account::create(['name' => 'C', 'type' => 'checking', 'currency' => 'USD']);
}

function anomCat(string $name = 'Dining'): Category
{
    return Category::create(['name' => $name, 'slug' => strtolower($name), 'kind' => 'expense']);
}

function makeSpend(Account $a, Category $c, float $amount, string $date): Transaction
{
    return Transaction::create([
        'account_id' => $a->id, 'category_id' => $c->id,
        'amount' => -abs($amount), 'currency' => 'USD',
        'occurred_on' => $date, 'description' => 'x', 'status' => 'cleared',
    ]);
}

it('flags a recent charge >2.5σ above the 90-day mean', function () {
    authedInHousehold();
    $a = anomAcc();
    $c = anomCat();
    for ($i = 10; $i < 100; $i += 10) {
        makeSpend($a, $c, 20 + ($i % 7), now()->subDays($i)->toDateString());
    }
    // Today: 300 → way above the ~20 mean.
    $spike = makeSpend($a, $c, 300, now()->toDateString());

    $hits = (new SpendingAnomalyDetector)->recentAnomalies();
    expect($hits)->toHaveCount(1)
        ->and($hits->first()['transaction']->id)->toBe($spike->id);
});

it('does not flag when the baseline has fewer than minSamples', function () {
    authedInHousehold();
    $a = anomAcc();
    $c = anomCat();
    makeSpend($a, $c, 10, now()->subDays(40)->toDateString());
    makeSpend($a, $c, 10, now()->subDays(30)->toDateString());
    makeSpend($a, $c, 500, now()->toDateString());

    expect((new SpendingAnomalyDetector)->recentAnomalies())->toHaveCount(0);
});

it('excludes positive amounts from the baseline and detection', function () {
    authedInHousehold();
    $a = anomAcc();
    $c = anomCat();
    for ($i = 10; $i < 100; $i += 10) {
        makeSpend($a, $c, 20, now()->subDays($i)->toDateString());
    }
    // Positive amount today — a refund should not be considered an anomaly.
    Transaction::create([
        'account_id' => $a->id, 'category_id' => $c->id,
        'amount' => 500, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'refund', 'status' => 'cleared',
    ]);

    expect((new SpendingAnomalyDetector)->recentAnomalies())->toHaveCount(0);
});

it('radar surfaces anomalies tile', function () {
    authedInHousehold();
    $a = anomAcc();
    $c = anomCat();
    for ($i = 10; $i < 100; $i += 10) {
        makeSpend($a, $c, 20, now()->subDays($i)->toDateString());
    }
    makeSpend($a, $c, 400, now()->toDateString());

    Livewire::test('attention-radar')
        ->assertSet('spendingAnomalies', 1)
        ->assertSee(__('Unusual charges ≤ 7d'));
});

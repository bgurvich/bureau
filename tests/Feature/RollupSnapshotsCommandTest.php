<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Property;
use App\Models\Snapshot;
use App\Models\Transaction;
use App\Models\Transfer;
use Carbon\CarbonImmutable;

function setupForRollup(): Account
{
    authedInHousehold('Rollup');

    return Account::create([
        'type' => 'bank', 'name' => 'Main', 'institution' => 'Chase',
        'currency' => 'USD', 'opening_balance' => 1000, 'include_in_net_worth' => true,
    ]);
}

it('writes monthly net-worth and cashflow snapshots', function () {
    CarbonImmutable::setTestNow('2026-04-10');
    $account = setupForRollup();

    $food = Category::create(['kind' => 'expense', 'name' => 'Food', 'slug' => 'food']);

    // March transactions (the month we're rolling up)
    Transaction::create([
        'account_id' => $account->id, 'category_id' => $food->id,
        'occurred_on' => '2026-03-05', 'amount' => -60, 'currency' => 'USD',
        'description' => 'Groceries', 'status' => 'cleared',
    ]);
    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-03-15', 'amount' => 2500, 'currency' => 'USD',
        'description' => 'Paycheck', 'status' => 'cleared',
    ]);
    // April transaction — should NOT count
    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-04-02', 'amount' => -20, 'currency' => 'USD',
        'status' => 'cleared',
    ]);

    test()->artisan('snapshots:rollup', ['--month' => '2026-03'])->assertSuccessful();

    $cashflow = Snapshot::where('kind', 'cashflow')->whereDate('taken_on', '2026-03-01')->first();
    expect($cashflow)->not->toBeNull()
        ->and($cashflow->payload['income'])->toEqual(2500.0)
        ->and($cashflow->payload['expense'])->toEqual(60.0)
        ->and($cashflow->payload['net'])->toEqual(2440.0)
        ->and($cashflow->payload['by_category']['Food'])->toEqual(-60.0);

    $netWorth = Snapshot::where('kind', 'net_worth')->whereDate('taken_on', '2026-03-31')->first();
    // opening 1000 + March txns (-60 + 2500) = 3440, April ignored
    expect($netWorth)->not->toBeNull()
        ->and($netWorth->payload['accounts'])->toEqual(3440.0)
        ->and($netWorth->payload['total'])->toEqual(3440.0);

    CarbonImmutable::setTestNow();
});

it('uses purchase price as a fallback when an asset has no valuation', function () {
    CarbonImmutable::setTestNow('2026-04-10');
    setupForRollup();

    Property::create([
        'kind' => 'home', 'name' => 'House', 'purchase_price' => 450000, 'purchase_currency' => 'USD',
    ]);

    test()->artisan('snapshots:rollup', ['--month' => '2026-03'])->assertSuccessful();

    $netWorth = Snapshot::where('kind', 'net_worth')->firstOrFail();
    expect($netWorth->payload['assets'])->toEqual(450000.0)
        ->and($netWorth->payload['by_kind']['property'])->toEqual(450000.0);

    CarbonImmutable::setTestNow();
});

it('is idempotent — rerunning updates the existing row rather than inserting', function () {
    CarbonImmutable::setTestNow('2026-04-10');
    setupForRollup();

    test()->artisan('snapshots:rollup', ['--month' => '2026-03']);
    test()->artisan('snapshots:rollup', ['--month' => '2026-03']);

    expect(Snapshot::where('kind', 'net_worth')->count())->toBe(1);
    expect(Snapshot::where('kind', 'cashflow')->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

it('ignores transfer legs the same as the accounts drill-down does', function () {
    CarbonImmutable::setTestNow('2026-04-10');
    $from = setupForRollup();
    $to = Account::create([
        'type' => 'cash', 'name' => 'Cash', 'currency' => 'USD',
        'opening_balance' => 0, 'include_in_net_worth' => true,
    ]);

    // Move $200 from bank to cash on March 20 — net worth should stay flat
    Transfer::create([
        'from_account_id' => $from->id,
        'to_account_id' => $to->id,
        'from_amount' => 200, 'to_amount' => 200,
        'from_currency' => 'USD', 'to_currency' => 'USD',
        'occurred_on' => '2026-03-20',
        'status' => 'cleared',
    ]);

    test()->artisan('snapshots:rollup', ['--month' => '2026-03']);

    $netWorth = Snapshot::where('kind', 'net_worth')->firstOrFail();
    expect($netWorth->payload['accounts'])->toEqual(1000.0);

    CarbonImmutable::setTestNow();
});

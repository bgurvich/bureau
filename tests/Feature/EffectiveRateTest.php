<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Contact;
use App\Models\LoanTerm;
use App\Models\Transaction;
use App\Support\EffectiveRate;
use Carbon\CarbonImmutable;
use Database\Seeders\SystemCategoriesSeeder;
use Livewire\Livewire;

function seedForRates(): Account
{
    authedInHousehold();
    (new SystemCategoriesSeeder)->run();

    return Account::create([
        'type' => 'credit',
        'name' => 'Amex',
        'currency' => 'USD',
        'opening_balance' => -1000,
        'include_in_net_worth' => true,
    ]);
}

it('returns null when the account type carries no interest concept', function () {
    authedInHousehold();
    (new SystemCategoriesSeeder)->run();

    $cash = Account::create([
        'type' => 'gift_card', 'name' => 'Amazon GC',
        'currency' => 'USD', 'opening_balance' => 50,
    ]);

    expect(EffectiveRate::forAccount($cash))->toBeNull();
});

it('computes APR and APY from monthly interest on a credit account', function () {
    CarbonImmutable::setTestNow('2026-04-15');
    $account = seedForRates();
    $interest = Category::where('slug', 'interest-paid')->value('id');

    // Charge $15 interest in March (prior full month) on a ~$1000 balance.
    Transaction::create([
        'account_id' => $account->id,
        'category_id' => $interest,
        'occurred_on' => '2026-03-28',
        'amount' => -15,
        'currency' => 'USD',
        'description' => 'Interest charge',
        'status' => 'cleared',
    ]);

    $rate = EffectiveRate::forAccount($account, monthsBack: 1);
    expect($rate)->not->toBeNull()
        ->and($rate['months_evaluated'])->toBe(1)
        ->and(round($rate['average_monthly_rate'], 4))->toBeBetween(0.01, 0.02)
        ->and($rate['apr'])->toBeGreaterThan($rate['average_monthly_rate'] * 11.99)
        ->and($rate['apy'])->toBeGreaterThan($rate['apr']);

    CarbonImmutable::setTestNow();
});

it('skips months with no interest and returns null when nothing qualifies', function () {
    CarbonImmutable::setTestNow('2026-04-15');
    $account = seedForRates();

    expect(EffectiveRate::forAccount($account, monthsBack: 3))->toBeNull();

    CarbonImmutable::setTestNow();
});

it('SystemCategoriesSeeder is idempotent', function () {
    authedInHousehold();
    (new SystemCategoriesSeeder)->run();
    (new SystemCategoriesSeeder)->run();

    expect(Category::where('slug', 'interest-paid')->count())->toBe(1);
    expect(Category::where('slug', 'interest-earned')->count())->toBe(1);
});

it('auto-fills the counterparty on an interest-paid transaction from the account', function () {
    authedInHousehold();
    (new SystemCategoriesSeeder)->run();

    $amex = Contact::create(['kind' => 'org', 'display_name' => 'American Express']);
    $account = Account::create([
        'type' => 'credit', 'name' => 'Amex Gold', 'currency' => 'USD',
        'opening_balance' => -500, 'counterparty_contact_id' => $amex->id,
    ]);
    $interest = Category::where('slug', 'interest-paid')->value('id');

    Livewire::test('inspector.transaction-form')
        ->set('account_id', $account->id)
        ->set('occurred_on', now()->toDateString())
        ->set('amount', '-12.50')
        ->set('currency', 'USD')
        ->set('status', 'cleared')
        ->set('category_id', $interest)
        ->call('save');

    $txn = Transaction::firstWhere('amount', -12.50);
    expect($txn)->not->toBeNull()
        ->and($txn->counterparty_contact_id)->toBe($amex->id);
});

it('real-time fills counterparty the moment the user picks interest category', function () {
    authedInHousehold();
    (new SystemCategoriesSeeder)->run();

    $amex = Contact::create(['kind' => 'org', 'display_name' => 'American Express']);
    $account = Account::create([
        'type' => 'credit', 'name' => 'Amex Gold', 'currency' => 'USD',
        'opening_balance' => -500, 'counterparty_contact_id' => $amex->id,
    ]);
    $interest = Category::where('slug', 'interest-paid')->value('id');

    Livewire::test('inspector.transaction-form')
        ->set('account_id', $account->id)
        ->set('category_id', $interest)
        ->assertSet('counterparty_contact_id', $amex->id);
});

it('renders contract rate and drift on a loan account in the accounts drill-down', function () {
    CarbonImmutable::setTestNow('2026-04-15');
    authedInHousehold();
    (new SystemCategoriesSeeder)->run();

    $loan = Account::create([
        'type' => 'loan', 'name' => 'Car Loan', 'currency' => 'USD',
        'opening_balance' => -20000, 'include_in_net_worth' => true,
    ]);
    LoanTerm::create([
        'account_id' => $loan->id,
        'direction' => 'borrowed',
        'principal' => 20000,
        'principal_currency' => 'USD',
        'interest_rate' => 6.25,
        'rate_type' => 'fixed',
    ]);

    Livewire::test('accounts-index')
        ->assertSee('6.25%')
        ->assertSee(__('fixed'));

    CarbonImmutable::setTestNow();
});

it('shows the commitments radar APR tile when credit balances carry interest', function () {
    CarbonImmutable::setTestNow('2026-04-15');
    authedInHousehold();
    (new SystemCategoriesSeeder)->run();

    $account = Account::create([
        'type' => 'credit', 'name' => 'Amex', 'currency' => 'USD',
        'opening_balance' => -2000, 'include_in_net_worth' => true, 'is_active' => true,
    ]);
    $interest = Category::where('slug', 'interest-paid')->value('id');

    // Three months of ~$25 interest charges.
    foreach (['2026-01-28', '2026-02-28', '2026-03-28'] as $date) {
        Transaction::create([
            'account_id' => $account->id,
            'category_id' => $interest,
            'occurred_on' => $date,
            'amount' => -25,
            'currency' => 'USD',
            'description' => 'Interest',
            'status' => 'cleared',
        ]);
    }

    Livewire::test('commitments-radar')
        ->assertSee(__('Avg APR on credit'))
        ->assertSee(__('Interest (12mo)'))
        ->assertSee('75.00');

    CarbonImmutable::setTestNow();
});

it('does not overwrite an explicitly set counterparty on interest transactions', function () {
    authedInHousehold();
    (new SystemCategoriesSeeder)->run();

    $amex = Contact::create(['kind' => 'org', 'display_name' => 'American Express']);
    $other = Contact::create(['kind' => 'org', 'display_name' => 'Different Recipient']);
    $account = Account::create([
        'type' => 'credit', 'name' => 'Amex Gold', 'currency' => 'USD',
        'opening_balance' => -500, 'counterparty_contact_id' => $amex->id,
    ]);
    $interest = Category::where('slug', 'interest-paid')->value('id');

    Livewire::test('inspector.transaction-form')
        ->set('account_id', $account->id)
        ->set('occurred_on', now()->toDateString())
        ->set('amount', '-10')
        ->set('currency', 'USD')
        ->set('status', 'cleared')
        ->set('category_id', $interest)
        ->set('counterparty_contact_id', $other->id)
        ->call('save');

    $txn = Transaction::firstWhere('amount', -10);
    expect($txn->counterparty_contact_id)->toBe($other->id);
});

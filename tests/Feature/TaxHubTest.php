<?php

use App\Models\Account;
use App\Models\Contact;
use App\Models\TaxDocument;
use App\Models\TaxEstimatedPayment;
use App\Models\TaxYear;
use Livewire\Livewire;

function seedTaxYear(int $year = 2025, string $state = 'prep'): TaxYear
{
    return TaxYear::create([
        'year' => $year,
        'jurisdiction' => 'US-federal',
        'state' => $state,
    ]);
}

it('creates a tax year via the inspector form', function () {
    authedInHousehold();

    Livewire::test('inspector.tax-year-form')
        ->set('year', '2025')
        ->set('jurisdiction', 'US-federal')
        ->set('filing_status', 'single')
        ->set('state', 'prep')
        ->call('save');

    $ty = TaxYear::firstOrFail();
    expect($ty->year)->toBe(2025)
        ->and($ty->jurisdiction)->toBe('US-federal')
        ->and($ty->filing_status)->toBe('single')
        ->and($ty->state)->toBe('prep');
});

it('rejects a duplicate year+jurisdiction combo via unique validation', function () {
    authedInHousehold();
    seedTaxYear(2025);

    Livewire::test('inspector.tax-year-form')
        ->set('year', '2025')
        ->set('jurisdiction', 'US-federal')
        ->set('state', 'prep')
        ->call('save')
        ->assertHasErrors(['year']);

    expect(TaxYear::count())->toBe(1);
});

it('edits an existing tax year and flips state to filed', function () {
    authedInHousehold();
    $ty = seedTaxYear(2024, 'prep');

    Livewire::test('inspector.tax-year-form', ['id' => $ty->id])
        ->set('state', 'filed')
        ->set('filed_on', '2025-04-10')
        ->set('settlement_amount', '432.10')
        ->call('save');

    $ty->refresh();
    expect($ty->state)->toBe('filed')
        ->and($ty->filed_on->toDateString())->toBe('2025-04-10')
        ->and((float) $ty->settlement_amount)->toBe(432.10);
});

it('creates a tax document under the pre-seeded tax year', function () {
    authedInHousehold();
    $ty = seedTaxYear();
    $emp = Contact::create(['kind' => 'org', 'display_name' => 'Acme Corp']);

    Livewire::test('inspector.tax-document-form', ['parentId' => $ty->id])
        ->set('kind', 'W-2')
        ->set('from_contact_id', $emp->id)
        ->set('received_on', '2026-01-31')
        ->set('amount', '75000.00')
        ->call('save');

    $doc = TaxDocument::firstOrFail();
    expect($doc->tax_year_id)->toBe($ty->id)
        ->and($doc->kind)->toBe('W-2')
        ->and($doc->from_contact_id)->toBe($emp->id)
        ->and((float) $doc->amount)->toBe(75000.00);
});

it('creates a tax estimated payment under the pre-seeded year', function () {
    authedInHousehold();
    $ty = seedTaxYear();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    Livewire::test('inspector.tax-estimated-payment-form', ['parentId' => $ty->id])
        ->set('quarter', 'Q1')
        ->set('due_on', '2026-04-15')
        ->set('amount', '2500.00')
        ->set('account_id', $account->id)
        ->call('save');

    $pmt = TaxEstimatedPayment::firstOrFail();
    expect($pmt->quarter)->toBe('Q1')
        ->and($pmt->due_on->toDateString())->toBe('2026-04-15')
        ->and((float) $pmt->amount)->toBe(2500.00)
        ->and($pmt->account_id)->toBe($account->id)
        ->and($pmt->paid_on)->toBeNull();
});

it('Tax hub renders years with their doc + payment counts', function () {
    authedInHousehold();
    $ty = seedTaxYear(2024, 'filed');
    $ty->documents()->create(['kind' => 'W-2', 'amount' => 60000]);
    $ty->documents()->create(['kind' => '1099-INT', 'amount' => 120]);
    $ty->estimatedPayments()->create(['quarter' => 'Q1', 'due_on' => '2024-04-15', 'paid_on' => '2024-04-14', 'amount' => 1000]);

    Livewire::test('tax-hub')
        ->assertSee('2024')
        ->assertSee('W-2')
        ->assertSee('1099-INT')
        ->assertSee('Q1');
});

it('attention-radar counts unpaid estimated payments due within 30d', function () {
    authedInHousehold();
    $ty = seedTaxYear();

    // Counts: due today, unpaid
    $ty->estimatedPayments()->create(['quarter' => 'Q1', 'due_on' => now()->toDateString(), 'amount' => 1000]);

    // Counts: overdue, unpaid
    $ty->estimatedPayments()->create(['quarter' => 'Q2', 'due_on' => now()->subDays(10)->toDateString(), 'amount' => 1000]);

    // Doesn't count: paid
    $ty->estimatedPayments()->create(['quarter' => 'Q3', 'due_on' => now()->toDateString(), 'paid_on' => now()->toDateString(), 'amount' => 1000]);

    // Doesn't count: beyond 30d
    $ty->estimatedPayments()->create(['quarter' => 'Q4', 'due_on' => now()->addDays(60)->toDateString(), 'amount' => 1000]);

    $c = Livewire::test('attention-radar');
    expect($c->get('taxPaymentsDueSoon'))->toBe(2);
});

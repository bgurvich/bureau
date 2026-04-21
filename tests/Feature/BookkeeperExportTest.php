<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Transaction;
use App\Support\CurrentHousehold;

function setupForExport(): array
{
    $user = authedInHousehold('Export Co');
    $household = CurrentHousehold::get();

    $account = Account::create([
        'type' => 'checking', 'name' => 'Chase Checking', 'external_code' => '1000',
        'institution' => 'Chase', 'currency' => 'USD', 'opening_balance' => 1000,
    ]);

    $category = Category::create([
        'kind' => 'expense', 'slug' => 'food/groceries', 'external_code' => '5100', 'name' => 'Groceries',
    ]);

    $vendor = Contact::create([
        'kind' => 'org', 'display_name' => 'Whole Foods', 'is_vendor' => true, 'tax_id' => 'V-1234',
    ]);

    Transaction::create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'counterparty_contact_id' => $vendor->id,
        'occurred_on' => '2026-04-05',
        'amount' => -42.50,
        'currency' => 'USD',
        'description' => 'Weekly grocery run',
        'reference_number' => 'RCP-001',
        'tax_amount' => 3.40,
        'tax_code' => 'SALES',
        'status' => 'cleared',
    ]);

    return [$user, $household, $account];
}

it('redirects guests away from the export endpoint', function () {
    $this->post('/bookkeeper/export', ['from' => '2026-04-01', 'to' => '2026-04-30'])
        ->assertRedirect('/login');
});

it('streams a zip for authenticated users', function () {
    [$user] = setupForExport();

    $response = $this->actingAs($user)->post('/bookkeeper/export', [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/zip');
    expect($response->headers->get('Content-Disposition'))->toContain('.zip');
});

it('rejects an invalid date range', function () {
    [$user] = setupForExport();

    $this->actingAs($user)->post('/bookkeeper/export', [
        'from' => '2026-04-30',
        'to' => '2026-04-01',
    ])->assertInvalid('to');
});

it('places expected files in the zip with transaction rows from the period', function () {
    [$user] = setupForExport();

    $response = $this->actingAs($user)->post('/bookkeeper/export', [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
    ]);

    $tmp = tempnam(sys_get_temp_dir(), 'bkpr-test');
    file_put_contents($tmp, $response->streamedContent());

    $zip = new ZipArchive;
    expect($zip->open($tmp))->toBeTrue();

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    expect($names)->toContain('README.md', 'accounts.csv', 'categories.csv', 'contacts.csv', 'transactions.csv', 'transfers.csv');

    $txnCsv = $zip->getFromName('transactions.csv');
    expect($txnCsv)->toContain('Weekly grocery run')
        ->and($txnCsv)->toContain('1000')       // account external_code
        ->and($txnCsv)->toContain('5100')       // category external_code
        ->and($txnCsv)->toContain('RCP-001')    // reference_number
        ->and($txnCsv)->toContain('SALES')      // tax_code
        ->and($txnCsv)->toContain('V-1234');    // counterparty tax_id

    $contactsCsv = $zip->getFromName('contacts.csv');
    expect($contactsCsv)->toContain('Whole Foods');

    $zip->close();
    @unlink($tmp);
});

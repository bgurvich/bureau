<?php

use App\Models\Account;
use App\Models\Contact;

it('shows the account institution value in the institution column', function () {
    authedInHousehold();

    Account::create([
        'type' => 'checking', 'name' => 'Everyday',
        'currency' => 'USD', 'opening_balance' => 100,
        'institution' => 'Chase',
    ]);

    $this->get(route('fiscal.accounts'))
        ->assertOk()
        ->assertSee('Chase');
});

it('prefers the institution value over the linked vendor contact name', function () {
    authedInHousehold();

    // Regression: the column header + SQL sort on "institution" used to be
    // contradicted by a cell that preferred the vendor contact, so an
    // account with both set rendered the vendor instead of the institution.
    $vendor = Contact::create([
        'kind' => 'org',
        'display_name' => 'Chase Bank (contact)',
    ]);
    Account::create([
        'type' => 'checking', 'name' => 'Everyday',
        'currency' => 'USD', 'opening_balance' => 100,
        'institution' => 'Chase',
        'vendor_contact_id' => $vendor->id,
    ]);

    $html = $this->get(route('fiscal.accounts'))->assertOk()->getContent();

    $institutionPos = strpos($html, 'Chase');
    $vendorPos = strpos($html, 'Chase Bank (contact)');
    expect($institutionPos)->not->toBeFalse()
        ->and($vendorPos)->not->toBeFalse()
        ->and($institutionPos)->toBeLessThan($vendorPos);
});

it('falls back to the linked vendor display_name when the institution is empty', function () {
    authedInHousehold();

    // Gift cards use the vendor relation in place of a free-text institution.
    $vendor = Contact::create([
        'kind' => 'org',
        'display_name' => 'Starbucks',
    ]);
    Account::create([
        'type' => 'gift_card', 'name' => 'SBUX card',
        'currency' => 'USD', 'opening_balance' => 25,
        'vendor_contact_id' => $vendor->id,
    ]);

    $this->get(route('fiscal.accounts'))
        ->assertOk()
        ->assertSee('Starbucks');
});

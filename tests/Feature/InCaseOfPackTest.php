<?php

use App\Models\Account;
use App\Models\Contact;
use App\Models\Document;
use App\Models\OnlineAccount;
use App\Models\Prescription;

it('renders the in-case-of pack with flagged + favorite records', function () {
    $user = authedInHousehold();

    Document::create([
        'kind' => 'passport', 'label' => 'US Passport', 'number' => 'X1234',
        'holder_user_id' => $user->id, 'expires_on' => now()->addYears(3)->toDateString(),
        'in_case_of_pack' => true,
    ]);
    Document::create([
        'kind' => 'license', 'label' => 'Driver license', 'number' => 'DL-7',
        'holder_user_id' => $user->id,
    ]);

    Contact::create(['kind' => 'person', 'display_name' => 'Emergency Sibling', 'favorite' => true]);
    Contact::create(['kind' => 'person', 'display_name' => 'Random Acquaintance']);

    OnlineAccount::create([
        'user_id' => $user->id, 'service_name' => 'Fastmail',
        'login_email' => 'me@example.com', 'importance_tier' => 'critical',
        'in_case_of_pack' => true, 'mfa_method' => 'totp',
    ]);

    Account::create([
        'type' => 'checking', 'name' => 'Chase Checking', 'currency' => 'USD',
        'opening_balance' => 1000, 'is_active' => true, 'external_code' => '****4421',
    ]);
    Account::create([
        'type' => 'checking', 'name' => 'Old Closed', 'currency' => 'USD',
        'opening_balance' => 0, 'is_active' => false,
    ]);

    Prescription::create([
        'name' => 'Lisinopril', 'dosage' => '10mg', 'schedule' => 'daily',
    ]);

    $this->get('/in-case-of')
        ->assertOk()
        ->assertSee('US Passport')
        ->assertDontSee('Driver license')  // not flagged
        ->assertSee('Emergency Sibling')
        ->assertDontSee('Random Acquaintance')
        ->assertSee('Fastmail')
        ->assertSee('Chase Checking')
        ->assertDontSee('Old Closed')  // inactive
        ->assertSee('Lisinopril');
});

it('shows an empty state when nothing is flagged', function () {
    authedInHousehold();

    $this->get('/in-case-of')
        ->assertOk()
        ->assertSee('Nothing flagged yet.');
});

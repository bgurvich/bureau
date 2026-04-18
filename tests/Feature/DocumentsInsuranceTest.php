<?php

use App\Models\Contact;
use App\Models\Contract;
use App\Models\Document;
use App\Models\InsurancePolicy;

it('renders the Documents drill-down with an expiring passport', function () {
    $user = authedInHousehold();

    Document::create([
        'kind' => 'passport',
        'holder_user_id' => $user->id,
        'label' => 'US Passport',
        'number' => 'X1234567',
        'issued_on' => now()->subYears(5)->toDateString(),
        'expires_on' => now()->addDays(45)->toDateString(),
        'in_case_of_pack' => true,
    ]);

    $this->get('/documents')
        ->assertOk()
        ->assertSee('US Passport')
        ->assertSee('passport');
});

it('filters documents to the in-case-of pack', function () {
    authedInHousehold();

    Document::create(['kind' => 'passport', 'label' => 'Passport A', 'in_case_of_pack' => true]);
    Document::create(['kind' => 'license', 'label' => 'Library card', 'in_case_of_pack' => false]);

    Livewire\Livewire::test('documents-index')
        ->set('inCaseOfOnly', true)
        ->assertSee('Passport A')
        ->assertDontSee('Library card');
});

it('renders the Insurance drill-down with policy details', function () {
    authedInHousehold();

    $carrier = Contact::create(['kind' => 'org', 'display_name' => 'State Farm']);
    $contract = Contract::create([
        'kind' => 'insurance',
        'title' => 'Auto Insurance',
        'starts_on' => now()->subMonths(3)->toDateString(),
        'ends_on' => now()->addMonths(9)->toDateString(),
        'monthly_cost_amount' => 125,
        'monthly_cost_currency' => 'USD',
        'state' => 'active',
    ]);
    InsurancePolicy::create([
        'contract_id' => $contract->id,
        'coverage_kind' => 'auto',
        'policy_number' => 'SF-99999',
        'carrier_contact_id' => $carrier->id,
        'premium_amount' => 125,
        'premium_currency' => 'USD',
        'premium_cadence' => 'monthly',
        'coverage_amount' => 100000,
        'coverage_currency' => 'USD',
    ]);

    $this->get('/insurance')
        ->assertOk()
        ->assertSee('Auto Insurance')
        ->assertSee('SF-99999')
        ->assertSee('State Farm');
});

it('filters insurance by coverage kind', function () {
    authedInHousehold();

    $auto = Contract::create(['kind' => 'insurance', 'title' => 'Auto P', 'state' => 'active']);
    InsurancePolicy::create(['contract_id' => $auto->id, 'coverage_kind' => 'auto']);

    $home = Contract::create(['kind' => 'insurance', 'title' => 'Home P', 'state' => 'active']);
    InsurancePolicy::create(['contract_id' => $home->id, 'coverage_kind' => 'home']);

    $this->get('/insurance?coverage=home')
        ->assertOk()
        ->assertSee('Home P')
        ->assertDontSee('Auto P');
});

<?php

use App\Models\Pet;
use App\Models\PetLicense;
use Livewire\Livewire;

it('creates a pet license via the inspector form', function () {
    authedInHousehold();
    $pet = Pet::create(['species' => 'dog', 'name' => 'Luna', 'is_active' => true]);

    Livewire::test('inspector.pet-license-form', ['petId' => $pet->id])
        ->set('authority', 'Alameda County, CA')
        ->set('license_number', 'DOG-12345')
        ->set('issued_on', '2026-04-01')
        ->set('expires_on', '2027-04-01')
        ->set('fee', '42.00')
        ->call('save');

    $l = PetLicense::firstOrFail();
    expect($l->pet_id)->toBe($pet->id)
        ->and($l->authority)->toBe('Alameda County, CA')
        ->and($l->license_number)->toBe('DOG-12345')
        ->and($l->issued_on->toDateString())->toBe('2026-04-01')
        ->and($l->expires_on->toDateString())->toBe('2027-04-01')
        ->and((float) $l->fee)->toBe(42.0);
});

it('rejects expires_on that precedes issued_on', function () {
    authedInHousehold();
    $pet = Pet::create(['species' => 'dog', 'name' => 'Luna', 'is_active' => true]);

    Livewire::test('inspector.pet-license-form', ['petId' => $pet->id])
        ->set('authority', 'Alameda County, CA')
        ->set('issued_on', '2026-04-01')
        ->set('expires_on', '2025-01-01')
        ->call('save')
        ->assertHasErrors(['expires_on']);

    expect(PetLicense::count())->toBe(0);
});

it('accepts a historical expires_on with no issued_on set', function () {
    authedInHousehold();
    $pet = Pet::create(['species' => 'dog', 'name' => 'Luna', 'is_active' => true]);

    Livewire::test('inspector.pet-license-form', ['petId' => $pet->id])
        ->set('authority', 'City Hall')
        ->set('expires_on', '2024-01-01')
        ->call('save')
        ->assertHasNoErrors();

    expect(PetLicense::firstOrFail()->expires_on->toDateString())->toBe('2024-01-01');
});

it('lists licenses filtered by pet and status window', function () {
    authedInHousehold();
    $luna = Pet::create(['species' => 'dog', 'name' => 'Luna', 'is_active' => true]);
    $rex = Pet::create(['species' => 'dog', 'name' => 'Rex', 'is_active' => true]);

    PetLicense::create(['pet_id' => $luna->id, 'authority' => 'A', 'expires_on' => now()->addDays(15)->toDateString()]);
    PetLicense::create(['pet_id' => $luna->id, 'authority' => 'B', 'expires_on' => now()->addYear()->toDateString()]);
    PetLicense::create(['pet_id' => $rex->id, 'authority' => 'C', 'expires_on' => now()->subDays(5)->toDateString()]);

    $c = Livewire::test('pet-licenses-index');
    expect($c->get('licenses')->count())->toBe(3);

    $c->set('petFilter', $luna->id);
    expect($c->get('licenses')->count())->toBe(2);

    $c->set('petFilter', null)->set('statusFilter', 'expiring');
    expect($c->get('licenses')->count())->toBe(1)
        ->and($c->get('licenses')->first()->authority)->toBe('A');

    $c->set('statusFilter', 'expired');
    expect($c->get('licenses')->count())->toBe(1)
        ->and($c->get('licenses')->first()->authority)->toBe('C');
});

it('Attention radar counts pet licenses expiring ≤30d or already past', function () {
    authedInHousehold();
    $pet = Pet::create(['species' => 'dog', 'name' => 'Luna', 'is_active' => true]);

    PetLicense::create(['pet_id' => $pet->id, 'authority' => 'A', 'expires_on' => now()->addDays(15)->toDateString()]);  // ✓ within 30d
    PetLicense::create(['pet_id' => $pet->id, 'authority' => 'B', 'expires_on' => now()->subDays(2)->toDateString()]);   // ✓ past due
    PetLicense::create(['pet_id' => $pet->id, 'authority' => 'C', 'expires_on' => now()->addYear()->toDateString()]);    // ✗ far future

    $c = Livewire::test('attention-radar');
    expect($c->get('expiringPetLicenses'))->toBe(2);
});

it('Pets hub renders the Licenses tab panel', function () {
    authedInHousehold();

    Livewire::test('pets-hub')
        ->call('setTab', 'licenses')
        ->assertSet('tab', 'licenses')
        ->assertSeeLivewire('pet-licenses-index');
});

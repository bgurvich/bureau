<?php

use App\Models\Pet;
use App\Models\PetCheckup;
use App\Models\PetVaccination;
use Livewire\Livewire;

it('renders empty state when no active pets', function () {
    authedInHousehold();

    Livewire::test('pet-care-radar')
        ->assertSet('petCount', 0)
        ->assertSee(__('No active pets.'));
});

it('counts active pets, ignores inactive', function () {
    authedInHousehold();

    Pet::create(['species' => 'dog', 'name' => 'Rex', 'is_active' => true]);
    Pet::create(['species' => 'cat', 'name' => 'Mittens', 'is_active' => true]);
    Pet::create(['species' => 'dog', 'name' => 'Old dog', 'is_active' => false]);

    Livewire::test('pet-care-radar')->assertSet('petCount', 2);
});

it('counts vaccines expiring in next 30 days and excludes placeholder rows', function () {
    authedInHousehold();
    $pet = Pet::create(['species' => 'dog', 'name' => 'Rex', 'is_active' => true]);

    PetVaccination::create([
        'pet_id' => $pet->id,
        'vaccine_name' => 'Rabies',
        'administered_on' => now()->subYear()->toDateString(),
        'valid_until' => now()->addDays(10)->toDateString(),
    ]);
    PetVaccination::create([
        'pet_id' => $pet->id,
        'vaccine_name' => 'Bordetella',
        'administered_on' => null,
        'valid_until' => now()->addDays(5)->toDateString(),
    ]);
    PetVaccination::create([
        'pet_id' => $pet->id,
        'vaccine_name' => 'Far future',
        'administered_on' => now()->toDateString(),
        'valid_until' => now()->addDays(120)->toDateString(),
    ]);

    Livewire::test('pet-care-radar')->assertSet('vaccinationsDueSoon', 1);
});

it('counts overdue checkups', function () {
    authedInHousehold();
    $pet = Pet::create(['species' => 'cat', 'name' => 'Mittens', 'is_active' => true]);

    PetCheckup::create([
        'pet_id' => $pet->id,
        'kind' => 'annual_checkup',
        'next_due_on' => now()->subDays(3)->toDateString(),
    ]);
    PetCheckup::create([
        'pet_id' => $pet->id,
        'kind' => 'dental_cleaning',
        'next_due_on' => now()->addDays(30)->toDateString(),
    ]);

    Livewire::test('pet-care-radar')->assertSet('checkupsOverdue', 1);
});

it('surfaces the closest upcoming entries sorted by date with overdue flag', function () {
    authedInHousehold();
    $pet = Pet::create(['species' => 'dog', 'name' => 'Rex', 'is_active' => true]);

    PetVaccination::create([
        'pet_id' => $pet->id,
        'vaccine_name' => 'Rabies',
        'administered_on' => now()->subYear()->toDateString(),
        'valid_until' => now()->subDays(2)->toDateString(),
    ]);
    PetCheckup::create([
        'pet_id' => $pet->id,
        'kind' => 'grooming',
        'next_due_on' => now()->addDays(14)->toDateString(),
    ]);

    $component = Livewire::test('pet-care-radar');
    $upcoming = $component->instance()->upcoming;

    expect($upcoming)->toHaveCount(2)
        ->and($upcoming[0]['label'])->toBe('Rabies')
        ->and($upcoming[0]['overdue'])->toBeTrue()
        ->and($upcoming[1]['label'])->toBe('Grooming')
        ->and($upcoming[1]['overdue'])->toBeFalse();
});

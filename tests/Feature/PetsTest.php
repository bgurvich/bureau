<?php

declare(strict_types=1);

use App\Models\Pet;
use App\Models\PetCheckup;
use App\Models\PetVaccination;
use App\Models\Prescription;
use App\Support\PetVaccineTemplates;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('creates a pet through the extracted PetForm and seeds required vaccines', function () {
    authedInHousehold();

    // Pet form extracted to App\Livewire\Inspector\PetForm — state
    // lives on the child (no pet_ prefix) and `inspector-save` event
    // replaces the old parent-dispatched `save()`.
    Livewire::test('inspector.pet-form')
        ->set('species', 'dog')
        ->set('name', 'Rex')
        ->set('date_of_birth', '2020-06-15')
        ->call('save')
        ->assertDispatched('inspector-saved', type: 'pet')
        ->assertDispatched('inspector-form-saved');

    $pet = Pet::where('name', 'Rex')->firstOrFail();
    expect($pet->species)->toBe('dog')
        ->and($pet->date_of_birth?->toDateString())->toBe('2020-06-15')
        ->and($pet->primary_owner_user_id)->not->toBeNull();

    $seeded = $pet->vaccinations->pluck('vaccine_name')->sort()->values()->all();
    expect($seeded)->toBe(['DHPP', 'Leptospirosis', 'Rabies']);
    expect($pet->vaccinations->every(fn ($v) => $v->administered_on === null))->toBeTrue();
});

it('edits an existing pet through the extracted PetForm without re-seeding vaccines', function () {
    authedInHousehold();

    $pet = Pet::create(['species' => 'cat', 'name' => 'Whiskers']);
    PetVaccineTemplates::seedRequiredFor($pet);

    $before = $pet->vaccinations()->count();

    Livewire::test('inspector.pet-form', ['id' => $pet->id])
        ->assertSet('name', 'Whiskers') // mount loaded the record
        ->set('color', 'Tabby')
        ->call('save');

    expect($pet->fresh()->color)->toBe('Tabby')
        ->and($pet->vaccinations()->count())->toBe($before);
});

it('inspector shell save() forwards to PetForm via inspector-save event for type=pet', function () {
    authedInHousehold();

    // Shell's save() should early-return with the dispatch — no direct
    // persistence happens on the shell for extracted types.
    Livewire::test('inspector')
        ->dispatch('inspector-open', type: 'pet')
        ->call('save')
        ->assertDispatched('inspector-save');
});

it('PetVaccineTemplates::seedRequiredFor is idempotent', function () {
    authedInHousehold();

    $pet = Pet::create(['species' => 'cat', 'name' => 'Whiskers']);
    $first = PetVaccineTemplates::seedRequiredFor($pet);
    $second = PetVaccineTemplates::seedRequiredFor($pet);

    expect($first)->toBe(2) // Rabies + FVRCP
        ->and($second)->toBe(0)
        ->and($pet->vaccinations()->count())->toBe(2);
});

it('surfaces expired vaccinations on the pets list (rose badge cue)', function () {
    authedInHousehold();
    CarbonImmutable::setTestNow('2026-04-22');

    $pet = Pet::create(['species' => 'dog', 'name' => 'Rex']);
    PetVaccination::create([
        'pet_id' => $pet->id,
        'vaccine_name' => 'Rabies',
        'administered_on' => '2024-04-22',
        'valid_until' => '2025-04-22', // expired before "today"
    ]);

    Livewire::test('pets-index')
        ->assertSee('Rex')
        ->assertSee(__('overdue'));

    CarbonImmutable::setTestNow();
});

it('allows a prescription to point at a pet via the polymorphic subject', function () {
    authedInHousehold();

    $pet = Pet::create(['species' => 'dog', 'name' => 'Rex']);
    $rx = Prescription::create([
        'subject_type' => Pet::class,
        'subject_id' => $pet->id,
        'name' => 'Apoquel',
        'dosage' => '16mg daily',
    ]);

    expect($pet->prescriptions()->count())->toBe(1)
        ->and($rx->subject)->toBeInstanceOf(Pet::class);
});

it('pet_vaccination modal carries parentId pre-seed and saves to the right pet', function () {
    authedInHousehold();

    $pet = Pet::create(['species' => 'dog', 'name' => 'Rex']);

    Livewire::test('inspector', ['asModal' => true])
        ->dispatch('subentity-edit-open', type: 'pet_vaccination', id: null, parentId: $pet->id)
        ->assertSet('pv_pet_id', $pet->id)
        ->set('pv_vaccine_name', 'Rabies')
        ->set('pv_administered_on', '2026-04-22')
        ->set('pv_valid_until', '2029-04-22')
        ->call('save');

    $v = PetVaccination::where('pet_id', $pet->id)->where('vaccine_name', 'Rabies')->latest('id')->first();
    expect($v)->not->toBeNull()
        ->and($v->administered_on->toDateString())->toBe('2026-04-22')
        ->and($v->valid_until->toDateString())->toBe('2029-04-22');
});

it('pet_checkup modal saves with parentId and computes next_due_on', function () {
    authedInHousehold();

    $pet = Pet::create(['species' => 'cat', 'name' => 'Whiskers']);

    Livewire::test('inspector', ['asModal' => true])
        ->dispatch('subentity-edit-open', type: 'pet_checkup', id: null, parentId: $pet->id)
        ->assertSet('pc_pet_id', $pet->id)
        ->set('pc_kind', 'annual_checkup')
        ->set('pc_checkup_on', '2026-04-22')
        ->set('pc_next_due_on', '2027-04-22')
        ->call('save');

    $c = PetCheckup::where('pet_id', $pet->id)->latest('id')->first();
    expect($c)->not->toBeNull()
        ->and($c->kind)->toBe('annual_checkup')
        ->and($c->next_due_on->toDateString())->toBe('2027-04-22');
});

it('pet_vaccination save validates valid_until is after administered_on', function () {
    authedInHousehold();
    $pet = Pet::create(['species' => 'dog', 'name' => 'Rex']);

    Livewire::test('inspector', ['asModal' => true])
        ->dispatch('subentity-edit-open', type: 'pet_vaccination', id: null, parentId: $pet->id)
        ->set('pv_vaccine_name', 'Rabies')
        ->set('pv_administered_on', '2026-04-22')
        ->set('pv_valid_until', '2025-04-22') // before administered — invalid
        ->call('save')
        ->assertHasErrors('pv_valid_until');
});

it('alerts-bell surfaces expiring vaccinations and overdue checkups in total count', function () {
    authedInHousehold();
    CarbonImmutable::setTestNow('2026-04-22');

    $pet = Pet::create(['species' => 'dog', 'name' => 'Rex']);
    // Expiring in 5 days — inside 14d window
    PetVaccination::create([
        'pet_id' => $pet->id,
        'vaccine_name' => 'Rabies',
        'administered_on' => '2023-04-22',
        'valid_until' => '2026-04-27',
    ]);
    // Already overdue checkup
    PetCheckup::create([
        'pet_id' => $pet->id,
        'kind' => 'annual_checkup',
        'checkup_on' => '2025-04-01',
        'next_due_on' => '2026-04-01',
    ]);

    $c = Livewire::test('alerts-bell');
    expect($c->get('total'))->toBeGreaterThanOrEqual(2);

    CarbonImmutable::setTestNow();
});

it('pet-vaccinations-index "open" filter excludes expired and placeholder rows', function () {
    authedInHousehold();
    CarbonImmutable::setTestNow('2026-04-22');

    $pet = Pet::create(['species' => 'dog', 'name' => 'Rex']);
    // Open (administered + future expiry)
    PetVaccination::create([
        'pet_id' => $pet->id,
        'vaccine_name' => 'Open vaccine',
        'administered_on' => '2026-01-01',
        'valid_until' => '2028-01-01',
    ]);
    // Expired
    PetVaccination::create([
        'pet_id' => $pet->id,
        'vaccine_name' => 'Expired vaccine',
        'administered_on' => '2023-01-01',
        'valid_until' => '2024-01-01',
    ]);
    // Placeholder
    PetVaccination::create([
        'pet_id' => $pet->id,
        'vaccine_name' => 'Placeholder vaccine',
    ]);

    $names = Livewire::test('pet-vaccinations-index')
        ->instance()->rows->pluck('vaccine_name')->sort()->values()->all();
    expect($names)->toBe(['Open vaccine']);

    $expired = Livewire::test('pet-vaccinations-index')
        ->set('stateFilter', 'expired')
        ->instance()->rows->pluck('vaccine_name')->all();
    expect($expired)->toBe(['Expired vaccine']);

    $placeholders = Livewire::test('pet-vaccinations-index')
        ->set('stateFilter', 'placeholder')
        ->instance()->rows->pluck('vaccine_name')->all();
    expect($placeholders)->toBe(['Placeholder vaccine']);

    CarbonImmutable::setTestNow();
});

it('pet-checkups-index overdue filter only includes past-due next_due_on rows', function () {
    authedInHousehold();
    CarbonImmutable::setTestNow('2026-04-22');

    $pet = Pet::create(['species' => 'cat', 'name' => 'Whiskers']);
    PetCheckup::create([
        'pet_id' => $pet->id, 'kind' => 'annual_checkup',
        'next_due_on' => '2026-04-01', // overdue
    ]);
    PetCheckup::create([
        'pet_id' => $pet->id, 'kind' => 'dental_cleaning',
        'next_due_on' => '2026-05-01', // upcoming
    ]);

    $overdue = Livewire::test('pet-checkups-index')
        ->set('stateFilter', 'overdue')
        ->instance()->rows->pluck('kind')->all();
    expect($overdue)->toBe(['annual_checkup']);

    CarbonImmutable::setTestNow();
});

it('creates a pet checkup with a next-due date and it can be read back', function () {
    authedInHousehold();

    $pet = Pet::create(['species' => 'dog', 'name' => 'Rex']);
    PetCheckup::create([
        'pet_id' => $pet->id,
        'kind' => 'annual_checkup',
        'checkup_on' => '2026-04-10',
        'next_due_on' => '2027-04-10',
    ]);

    expect($pet->checkups()->first()->next_due_on->toDateString())->toBe('2027-04-10');
});

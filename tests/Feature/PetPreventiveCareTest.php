<?php

declare(strict_types=1);

use App\Models\Pet;
use App\Models\PetPreventiveCare;
use Livewire\Livewire;

it('creates a preventive care entry with auto-derived next_due_on', function () {
    authedInHousehold();
    $pet = Pet::create(['species' => 'dog', 'name' => 'Rex']);

    Livewire::test('inspector.pet-preventive-care-form', ['parentId' => $pet->id])
        ->set('kind', 'heartworm')
        ->set('applied_on', '2026-04-01')
        ->call('save');

    $row = PetPreventiveCare::firstOrFail();
    // heartworm default interval is 30 days → next_due = 2026-05-01.
    expect($row->pet_id)->toBe($pet->id)
        ->and($row->kind)->toBe('heartworm')
        ->and($row->interval_days)->toBe(30)
        ->and($row->next_due_on?->toDateString())->toBe('2026-05-01');
});

it('picking a different kind replaces the interval default on a new row', function () {
    authedInHousehold();
    $pet = Pet::create(['species' => 'dog', 'name' => 'Rex']);

    $c = Livewire::test('inspector.pet-preventive-care-form', ['parentId' => $pet->id])
        ->assertSet('kind', 'heartworm')
        ->assertSet('interval_days', '30');

    $c->set('kind', 'dental');
    expect($c->get('interval_days'))->toBe('365');
});

it('does not clobber user-typed interval when editing an existing row', function () {
    authedInHousehold();
    $pet = Pet::create(['species' => 'dog', 'name' => 'Rex']);
    $row = PetPreventiveCare::create([
        'pet_id' => $pet->id, 'kind' => 'heartworm', 'applied_on' => '2026-04-01',
        'interval_days' => 45, 'next_due_on' => '2026-05-16',
    ]);

    Livewire::test('inspector.pet-preventive-care-form', ['id' => $row->id])
        ->assertSet('interval_days', '45');
});

it('rejects next_due_on before applied_on', function () {
    authedInHousehold();
    $pet = Pet::create(['species' => 'dog', 'name' => 'Rex']);

    Livewire::test('inspector.pet-preventive-care-form', ['parentId' => $pet->id])
        ->set('kind', 'heartworm')
        ->set('applied_on', '2026-04-01')
        ->set('next_due_on', '2026-03-01')
        ->call('save')
        ->assertHasErrors(['next_due_on']);
});

it('Attention radar counts due-within-14-days preventive care, deduped per (pet, kind)', function () {
    authedInHousehold();
    $pet = Pet::create(['species' => 'dog', 'name' => 'Rex']);

    // Stale older heartworm log — due date passed, but superseded by
    // a newer log below so should NOT fire.
    PetPreventiveCare::create([
        'pet_id' => $pet->id, 'kind' => 'heartworm',
        'applied_on' => now()->subDays(90)->toDateString(),
        'interval_days' => 30,
        'next_due_on' => now()->subDays(60)->toDateString(),
    ]);
    // Newer heartworm log — due in the future beyond the window. Should NOT fire.
    PetPreventiveCare::create([
        'pet_id' => $pet->id, 'kind' => 'heartworm',
        'applied_on' => now()->subDays(5)->toDateString(),
        'interval_days' => 30,
        'next_due_on' => now()->addDays(25)->toDateString(),
    ]);
    // Flea/tick — latest log is due in 5 days. Should fire.
    PetPreventiveCare::create([
        'pet_id' => $pet->id, 'kind' => 'flea_tick',
        'applied_on' => now()->subDays(25)->toDateString(),
        'interval_days' => 30,
        'next_due_on' => now()->addDays(5)->toDateString(),
    ]);

    $c = Livewire::test('attention-radar');
    expect($c->get('petPreventiveCareDueSoon'))->toBe(1);
});

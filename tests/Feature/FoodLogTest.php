<?php

use App\Models\FoodEntry;
use Livewire\Livewire;

it('creates a food entry via the inspector form', function () {
    authedInHousehold();

    Livewire::test('inspector.food-entry-form')
        ->set('kind', 'meal')
        ->set('label', 'Oatmeal with berries')
        ->set('eaten_at', '2026-04-23T08:15')
        ->set('calories', '380')
        ->set('protein_g', '12.5')
        ->set('carbs_g', '65')
        ->set('fat_g', '7.2')
        ->call('save');

    $e = FoodEntry::firstOrFail();
    expect($e->kind)->toBe('meal')
        ->and($e->label)->toBe('Oatmeal with berries')
        ->and($e->calories)->toBe(380)
        ->and((float) $e->protein_g)->toBe(12.5)
        ->and((float) $e->carbs_g)->toBe(65.0)
        ->and((float) $e->fat_g)->toBe(7.2)
        ->and($e->source)->toBe('manual')
        ->and($e->user_id)->not->toBeNull();
});

it('requires label and a valid eaten_at', function () {
    authedInHousehold();

    Livewire::test('inspector.food-entry-form')
        ->set('kind', 'meal')
        ->set('label', '')
        ->call('save')
        ->assertHasErrors(['label']);

    expect(FoodEntry::count())->toBe(0);
});

it('index shows per-day entries and totals', function () {
    authedInHousehold();

    FoodEntry::create(['kind' => 'meal', 'label' => 'Breakfast', 'eaten_at' => '2026-04-23 08:00', 'calories' => 400, 'protein_g' => 20, 'carbs_g' => 50, 'fat_g' => 10]);
    FoodEntry::create(['kind' => 'meal', 'label' => 'Lunch', 'eaten_at' => '2026-04-23 12:30', 'calories' => 650, 'protein_g' => 35, 'carbs_g' => 70, 'fat_g' => 20]);
    FoodEntry::create(['kind' => 'snack', 'label' => 'Apple', 'eaten_at' => '2026-04-23 15:00', 'calories' => 90]);
    // Other day, excluded.
    FoodEntry::create(['kind' => 'meal', 'label' => 'Yesterday dinner', 'eaten_at' => '2026-04-22 19:00', 'calories' => 800]);

    $c = Livewire::test('food-log-index')->set('dateFilter', '2026-04-23');

    expect($c->get('entries')->count())->toBe(3);
    $totals = $c->get('totals');
    expect($totals['calories'])->toBe(1140)    // 400 + 650 + 90
        ->and($totals['protein_g'])->toBe(55.0)  // 20 + 35 (+ 0 from snack)
        ->and($totals['carbs_g'])->toBe(120.0)
        ->and($totals['fat_g'])->toBe(30.0);

    // Yesterday's calories bucket shows up in the 7-day window.
    $week = $c->get('weekTotals');
    expect($week)->toHaveCount(7)
        ->and($week['2026-04-22'])->toBe(800)
        ->and($week['2026-04-23'])->toBe(1140);
});

it('shift-day advances the focused date one step forward / back', function () {
    authedInHousehold();

    $c = Livewire::test('food-log-index')->set('dateFilter', '2026-04-23');
    $c->call('shiftDay', -1)->assertSet('dateFilter', '2026-04-22');
    $c->call('shiftDay', 2)->assertSet('dateFilter', '2026-04-24');
});

it('null nutrition fields don\'t break the totals', function () {
    authedInHousehold();

    FoodEntry::create(['kind' => 'meal', 'label' => 'No macros logged', 'eaten_at' => '2026-04-23 09:00']);

    $c = Livewire::test('food-log-index')->set('dateFilter', '2026-04-23');
    expect($c->get('totals')['calories'])->toBe(0)
        ->and($c->get('totals')['protein_g'])->toBe(0.0);
});

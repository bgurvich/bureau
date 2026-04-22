<?php

use App\Models\Category;
use Livewire\Livewire;

it('mapCategoryHint appends the hint to the target category, dedup case-insensitive', function () {
    authedInHousehold();
    $shopping = Category::create([
        'name' => 'Shopping', 'slug' => 'shopping', 'kind' => 'expense',
        'match_patterns' => 'General Retail',
    ]);

    Livewire::test('statements-import')
        ->call('mapCategoryHint', 'dummy-file-id', 'Merchandise', $shopping->id);

    expect($shopping->fresh()->match_patterns)->toBe("General Retail\nMerchandise");

    // Re-applying the same hint (case variant) should not grow the list.
    Livewire::test('statements-import')
        ->call('mapCategoryHint', 'dummy-file-id', 'merchandise', $shopping->id);

    expect($shopping->fresh()->match_patterns)->toBe("General Retail\nMerchandise");
});

it('createCategoryFromHint spawns a household expense category seeded with the hint', function () {
    authedInHousehold();

    Livewire::test('statements-import')
        ->call('createCategoryFromHint', 'dummy-file-id', 'Auto Rental');

    $c = Category::firstWhere('name', 'Auto Rental');
    expect($c)->not->toBeNull()
        ->and($c->kind)->toBe('expense')
        ->and($c->slug)->toBe('auto-rental')
        ->and($c->match_patterns)->toBe('Auto Rental');
});

it('createCategoryFromHint suffixes the slug when a clash exists', function () {
    authedInHousehold();
    Category::create(['name' => 'Existing', 'slug' => 'auto-rental', 'kind' => 'expense']);

    Livewire::test('statements-import')
        ->call('createCategoryFromHint', 'dummy-file-id', 'Auto Rental');

    $c = Category::firstWhere('name', 'Auto Rental');
    expect($c->slug)->toBe('auto-rental-1');
});

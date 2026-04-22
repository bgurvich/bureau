<?php

use App\Models\Category;

it('renders just the name when there is no parent', function () {
    authedInHousehold();
    $c = Category::create(['name' => 'Health', 'slug' => 'health', 'kind' => 'expense']);

    expect($c->displayLabel())->toBe('Health')
        ->and($c->displayLabel(includeKind: true))->toBe('Expense · Health');
});

it('disambiguates sibling categories under different parents', function () {
    authedInHousehold();
    $pets = Category::create(['name' => 'Pets', 'slug' => 'pets', 'kind' => 'expense']);
    $personal = Category::create(['name' => 'Personal', 'slug' => 'personal', 'kind' => 'expense']);

    $petsGrooming = Category::create(['name' => 'Grooming', 'slug' => 'pets/grooming', 'kind' => 'expense', 'parent_id' => $pets->id]);
    $personalGrooming = Category::create(['name' => 'Grooming', 'slug' => 'personal/grooming', 'kind' => 'expense', 'parent_id' => $personal->id]);

    // Load parent relation so displayLabel() has what it needs.
    $petsGrooming->load('parent');
    $personalGrooming->load('parent');

    expect($petsGrooming->displayLabel())->toBe('Pets · Grooming')
        ->and($personalGrooming->displayLabel())->toBe('Personal · Grooming')
        ->and($petsGrooming->displayLabel(includeKind: true))->toBe('Expense · Pets · Grooming');
});

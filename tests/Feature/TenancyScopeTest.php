<?php

use App\Models\Category;
use App\Models\Household;
use App\Support\CurrentHousehold;

it('scopes household-owned models by the current household', function () {
    $h1 = Household::create(['name' => 'H1', 'default_currency' => 'USD']);
    $h2 = Household::create(['name' => 'H2', 'default_currency' => 'USD']);

    CurrentHousehold::set($h1);
    Category::create(['kind' => 'expense', 'name' => 'H1 Food', 'slug' => 'h1-food']);

    CurrentHousehold::set($h2);
    Category::create(['kind' => 'expense', 'name' => 'H2 Food', 'slug' => 'h2-food']);

    // In H2's context, only H2 rows should be visible.
    expect(Category::count())->toBe(1)
        ->and(Category::first()?->name)->toBe('H2 Food');

    CurrentHousehold::set($h1);
    expect(Category::count())->toBe(1)
        ->and(Category::first()?->name)->toBe('H1 Food');

    CurrentHousehold::set(null);
});

it('auto-assigns household_id on create when CurrentHousehold is set', function () {
    $h = Household::create(['name' => 'H', 'default_currency' => 'USD']);
    CurrentHousehold::set($h);

    $c = Category::create(['kind' => 'expense', 'name' => 'Food', 'slug' => 'food']);

    expect($c->household_id)->toBe($h->id);

    CurrentHousehold::set(null);
});

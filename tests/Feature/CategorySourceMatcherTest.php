<?php

use App\Models\Category;
use App\Support\CategorySourceMatcher;

it('returns null when no categories have patterns', function () {
    authedInHousehold();
    Category::create(['name' => 'Plain', 'slug' => 'plain', 'kind' => 'expense']);

    expect(CategorySourceMatcher::match('Merchandise'))->toBeNull();
});

it('matches a plain-substring pattern case-insensitively', function () {
    authedInHousehold();
    $shopping = Category::create(['name' => 'Shopping', 'slug' => 'shopping', 'kind' => 'expense', 'match_patterns' => "Merchandise\nGeneral Retail"]);
    Category::create(['name' => 'Food', 'slug' => 'food', 'kind' => 'expense', 'match_patterns' => 'Restaurants']);

    expect(CategorySourceMatcher::match('Merchandise'))->toBe($shopping->id)
        ->and(CategorySourceMatcher::match('merchandise'))->toBe($shopping->id)
        ->and(CategorySourceMatcher::match('MERCHANDISE'))->toBe($shopping->id);
});

it('matches via regex anchors', function () {
    authedInHousehold();
    $health = Category::create(['name' => 'Health', 'slug' => 'health', 'kind' => 'expense', 'match_patterns' => '^Health\s+Care$']);

    expect(CategorySourceMatcher::match('Health Care'))->toBe($health->id)
        ->and(CategorySourceMatcher::match('Health Care Spending'))->toBeNull();
});

it('returns the first match on overlap and surfaces which pattern fired', function () {
    authedInHousehold();
    $first = Category::create(['name' => 'First', 'slug' => 'first', 'kind' => 'expense', 'match_patterns' => 'shop']);
    Category::create(['name' => 'Second', 'slug' => 'second', 'kind' => 'expense', 'match_patterns' => 'shopping']);

    $hit = CategorySourceMatcher::matchWithPattern('shopping');
    expect($hit)->toBe([$first->id, 'shop']);
});

it('skips categories with empty or whitespace-only patterns', function () {
    authedInHousehold();
    Category::create(['name' => 'Auto-skipped', 'slug' => 'auto-skip', 'kind' => 'expense', 'match_patterns' => "  \n  \n"]);

    expect(CategorySourceMatcher::match('anything'))->toBeNull();
});

it('ignores empty source labels', function () {
    authedInHousehold();
    Category::create(['name' => 'X', 'slug' => 'x', 'kind' => 'expense', 'match_patterns' => 'anything']);

    expect(CategorySourceMatcher::match(''))->toBeNull()
        ->and(CategorySourceMatcher::match('   '))->toBeNull();
});

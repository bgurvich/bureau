<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Support\CategoryRuleMatcher;

function catAcc(): Account
{
    return Account::create(['name' => 'C', 'type' => 'checking', 'currency' => 'USD']);
}

function makeCategory(string $name): Category
{
    return Category::create([
        'name' => $name,
        'slug' => strtolower(str_replace(' ', '-', $name)),
        'kind' => 'expense',
    ]);
}

it('categorizes a new transaction via a contains rule', function () {
    authedInHousehold();
    $cat = makeCategory('Groceries');
    CategoryRule::create([
        'category_id' => $cat->id, 'pattern_type' => 'contains', 'pattern' => 'trader joe',
    ]);

    $t = Transaction::create([
        'account_id' => catAcc()->id, 'amount' => -42, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'TRADER JOE #123 PORTLAND', 'status' => 'cleared',
    ]);

    expect($t->fresh()->category_id)->toBe($cat->id);
});

it('prefers the lowest-priority rule when multiple match', function () {
    authedInHousehold();
    $a = makeCategory('Dining');
    $b = makeCategory('Coffee');
    CategoryRule::create(['category_id' => $a->id, 'pattern_type' => 'contains', 'pattern' => 'cafe', 'priority' => 50]);
    CategoryRule::create(['category_id' => $b->id, 'pattern_type' => 'contains', 'pattern' => 'cafe', 'priority' => 10]);

    $t = Transaction::create([
        'account_id' => catAcc()->id, 'amount' => -5, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'cafe around corner', 'status' => 'cleared',
    ]);

    expect($t->fresh()->category_id)->toBe($b->id);
});

it('does not override an already-set category', function () {
    authedInHousehold();
    $a = makeCategory('Auto');
    $b = makeCategory('Manual');
    CategoryRule::create(['category_id' => $a->id, 'pattern_type' => 'contains', 'pattern' => 'shell']);

    $t = Transaction::create([
        'account_id' => catAcc()->id, 'amount' => -40, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'SHELL GAS', 'status' => 'cleared',
        'category_id' => $b->id,
    ]);

    expect($t->fresh()->category_id)->toBe($b->id);
});

it('matches a regex pattern case-insensitively', function () {
    authedInHousehold();
    $cat = makeCategory('Transit');
    CategoryRule::create(['category_id' => $cat->id, 'pattern_type' => 'regex', 'pattern' => '\\bUBER\\s*(TRIP|EATS)?\\b']);

    $t = Transaction::create([
        'account_id' => catAcc()->id, 'amount' => -12, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'uber trip 9pm', 'status' => 'cleared',
    ]);

    expect($t->fresh()->category_id)->toBe($cat->id);
});

it('silently skips a broken regex rule without crashing subsequent rules', function () {
    authedInHousehold();
    $bad = makeCategory('Bad');
    $good = makeCategory('Good');
    CategoryRule::create(['category_id' => $bad->id, 'pattern_type' => 'regex', 'pattern' => '[unterminated', 'priority' => 10]);
    CategoryRule::create(['category_id' => $good->id, 'pattern_type' => 'contains', 'pattern' => 'coffee', 'priority' => 20]);

    $t = Transaction::create([
        'account_id' => catAcc()->id, 'amount' => -5, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'coffee shop', 'status' => 'cleared',
    ]);

    expect($t->fresh()->category_id)->toBe($good->id);
});

it('artisan categories:apply categorizes existing uncategorized transactions', function () {
    authedInHousehold();
    $cat = makeCategory('Utilities');
    $t = Transaction::create([
        'account_id' => catAcc()->id, 'amount' => -80, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'PGE ELECTRIC', 'status' => 'cleared',
    ]);
    // Rule added after transaction — observer couldn't fire; the command is the catch-up.
    CategoryRule::create(['category_id' => $cat->id, 'pattern_type' => 'contains', 'pattern' => 'PGE']);
    expect($t->fresh()->category_id)->toBeNull();

    $this->artisan('categories:apply')
        ->expectsOutputToContain('Categorized 1')
        ->assertSuccessful();

    expect($t->fresh()->category_id)->toBe($cat->id);
});

it('returns null from attempt() when the description is empty', function () {
    authedInHousehold();
    $cat = makeCategory('X');
    CategoryRule::create(['category_id' => $cat->id, 'pattern_type' => 'contains', 'pattern' => 'x']);

    $t = Transaction::create([
        'account_id' => catAcc()->id, 'amount' => -1, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => '', 'status' => 'cleared',
    ]);

    expect(CategoryRuleMatcher::attempt($t))->toBeNull()
        ->and($t->fresh()->category_id)->toBeNull();
});

<?php

use App\Models\Account;
use App\Models\Tag;
use App\Models\TagRule;
use App\Models\Transaction;
use App\Support\TagRuleMatcher;

function tagAcc(): Account
{
    return Account::create(['name' => 'C', 'type' => 'checking', 'currency' => 'USD']);
}

function makeTag(string $name): Tag
{
    return Tag::create(['name' => $name, 'slug' => strtolower($name)]);
}

it('auto-attaches tags to a new transaction on description match', function () {
    authedInHousehold();
    $tag = makeTag('coffee');
    TagRule::create(['tag_id' => $tag->id, 'pattern_type' => 'contains', 'pattern' => 'starbucks', 'active' => true]);

    $acc = tagAcc();
    $t = Transaction::create([
        'account_id' => $acc->id, 'amount' => -7, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'STARBUCKS #123', 'status' => 'cleared',
    ]);

    expect($t->fresh()->tags->pluck('name')->all())->toContain('coffee');
});

it('attaches multiple tags when more than one rule matches', function () {
    authedInHousehold();
    $work = makeTag('work');
    $travel = makeTag('travel');
    TagRule::create(['tag_id' => $work->id, 'pattern_type' => 'contains', 'pattern' => 'delta', 'active' => true]);
    TagRule::create(['tag_id' => $travel->id, 'pattern_type' => 'contains', 'pattern' => 'delta', 'active' => true]);

    $acc = tagAcc();
    $t = Transaction::create([
        'account_id' => $acc->id, 'amount' => -400, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'DELTA AIRLINES', 'status' => 'cleared',
    ]);

    expect($t->fresh()->tags->pluck('name')->sort()->values()->all())->toEqual(['travel', 'work']);
});

it('does not detach tags the user already attached manually', function () {
    authedInHousehold();
    $tag = makeTag('personal');
    $acc = tagAcc();
    $t = Transaction::create([
        'account_id' => $acc->id, 'amount' => -10, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'nothing to match', 'status' => 'cleared',
    ]);
    $t->tags()->attach($tag->id);

    // Rule doesn't match; matcher runs, but manual tag must survive.
    TagRuleMatcher::attempt($t);
    expect($t->fresh()->tags->pluck('name')->all())->toContain('personal');
});

it('regex pattern matches case-insensitively', function () {
    authedInHousehold();
    $tag = makeTag('fuel');
    TagRule::create(['tag_id' => $tag->id, 'pattern_type' => 'regex', 'pattern' => '\\b(SHELL|BP|CHEVRON)\\b', 'active' => true]);

    $acc = tagAcc();
    $t = Transaction::create([
        'account_id' => $acc->id, 'amount' => -40, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'chevron station', 'status' => 'cleared',
    ]);

    expect($t->fresh()->tags->pluck('name')->all())->toContain('fuel');
});

it('broken regex is silently skipped', function () {
    authedInHousehold();
    $bad = makeTag('bad');
    $good = makeTag('good');
    TagRule::create(['tag_id' => $bad->id, 'pattern_type' => 'regex', 'pattern' => '[unterminated', 'active' => true]);
    TagRule::create(['tag_id' => $good->id, 'pattern_type' => 'contains', 'pattern' => 'foo', 'active' => true]);

    $acc = tagAcc();
    $t = Transaction::create([
        'account_id' => $acc->id, 'amount' => -1, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'foo bar', 'status' => 'cleared',
    ]);

    expect($t->fresh()->tags->pluck('name')->all())->toContain('good')
        ->not->toContain('bad');
});

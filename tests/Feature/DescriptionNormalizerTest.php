<?php

declare(strict_types=1);

use App\Models\Household;
use App\Support\CurrentHousehold;
use App\Support\DescriptionNormalizer;

it('returns the input untouched when no patterns are configured', function () {
    $h = Household::create(['name' => 'Test', 'default_currency' => 'USD']);
    CurrentHousehold::set($h);

    $result = DescriptionNormalizer::stripIgnoredPatterns('Purchase authorized on 07/30 NOW Mobile Xfinity.Com PA');

    expect($result)->toBe('Purchase authorized on 07/30 NOW Mobile Xfinity.Com PA');
});

it('strips configured phrases case-insensitively', function () {
    $h = Household::create([
        'name' => 'Test',
        'default_currency' => 'USD',
        'data' => ['vendor_ignore_patterns' => "purchase authorized on\npos purchase"],
    ]);
    CurrentHousehold::set($h);

    $result = DescriptionNormalizer::stripIgnoredPatterns('PURCHASE AUTHORIZED ON 07/30 Costco');

    expect($result)->not->toContain('PURCHASE AUTHORIZED')
        ->and($result)->toContain('Costco');
});

it('accepts regex syntax (alternation, anchors)', function () {
    $h = Household::create([
        'name' => 'Test',
        'default_currency' => 'USD',
        'data' => ['vendor_ignore_patterns' => 'ach transfer (from|to)'],
    ]);
    CurrentHousehold::set($h);

    $from = DescriptionNormalizer::stripIgnoredPatterns('ACH Transfer From Savings 9876');
    $to = DescriptionNormalizer::stripIgnoredPatterns('ACH Transfer To Brokerage 1234');

    expect($from)->not->toContain('Transfer')
        ->and($to)->not->toContain('Transfer');
});

it('skips malformed regex lines without aborting others', function () {
    $h = Household::create([
        'name' => 'Test',
        'default_currency' => 'USD',
        'data' => ['vendor_ignore_patterns' => "[unbalanced\npurchase authorized on"],
    ]);
    CurrentHousehold::set($h);

    $result = DescriptionNormalizer::stripIgnoredPatterns('Purchase authorized on 07/30 NOW Mobile');

    // First line is invalid regex — silently skipped. Second line still
    // applies, so "Purchase authorized on" is gone.
    expect($result)->not->toContain('Purchase authorized on')
        ->and($result)->toContain('NOW Mobile');
});

it('ignores blank lines in the patterns list', function () {
    $h = Household::create([
        'name' => 'Test',
        'default_currency' => 'USD',
        'data' => ['vendor_ignore_patterns' => "\n\npurchase authorized on\n\n"],
    ]);
    CurrentHousehold::set($h);

    $result = DescriptionNormalizer::stripIgnoredPatterns('Purchase authorized on 07/30 Costco');

    expect($result)->not->toContain('Purchase authorized on')
        ->and($result)->toContain('Costco');
});

afterEach(function () {
    CurrentHousehold::set(null);
});

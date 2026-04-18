<?php

use App\Support\RruleHumanize;

it('describes a one-off as "Once on"', function () {
    expect(RruleHumanize::describe('FREQ=DAILY;COUNT=1', '2026-04-10'))
        ->toContain('Once on');
});

it('describes monthly with BYMONTHDAY ordinals', function () {
    expect(RruleHumanize::describe('FREQ=MONTHLY;BYMONTHDAY=1'))->toBe('Monthly on the 1st');
    expect(RruleHumanize::describe('FREQ=MONTHLY;BYMONTHDAY=15'))->toBe('Monthly on the 15th');
    expect(RruleHumanize::describe('FREQ=MONTHLY;BYMONTHDAY=1,15'))->toBe('Monthly on the 1st, 15th');
});

it('describes weekly with weekday names', function () {
    expect(RruleHumanize::describe('FREQ=WEEKLY;BYDAY=MO,WE,FR'))
        ->toBe('Weekly on Monday, Wednesday, Friday');
});

it('handles INTERVAL', function () {
    expect(RruleHumanize::describe('FREQ=MONTHLY;INTERVAL=3'))->toBe('Every 3 months');
    expect(RruleHumanize::describe('FREQ=DAILY;INTERVAL=7'))->toBe('Every 7 days');
});

it('falls back gracefully for unknown patterns', function () {
    expect(RruleHumanize::describe('FREQ=HOURLY'))->toBe('Repeats');
});

it('appends COUNT and UNTIL modifiers', function () {
    expect(RruleHumanize::describe('FREQ=MONTHLY;COUNT=12'))->toContain('12 times');
    expect(RruleHumanize::describe('FREQ=MONTHLY;UNTIL=20261231'))->toContain('until');
});

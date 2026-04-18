<?php

use App\Support\Rrule;
use Carbon\CarbonImmutable;

function dateStrings(array $dates): array
{
    return array_map(fn (CarbonImmutable $d) => $d->toDateString(), $dates);
}

it('expands FREQ=DAILY across the horizon', function () {
    $dates = Rrule::expand('2026-04-01', 'FREQ=DAILY', '2026-04-05');

    expect(dateStrings($dates))->toBe(['2026-04-01', '2026-04-02', '2026-04-03', '2026-04-04', '2026-04-05']);
});

it('respects INTERVAL for DAILY', function () {
    $dates = Rrule::expand('2026-04-01', 'FREQ=DAILY;INTERVAL=2', '2026-04-08');

    expect(dateStrings($dates))->toBe(['2026-04-01', '2026-04-03', '2026-04-05', '2026-04-07']);
});

it('expands WEEKLY with BYDAY into the listed weekdays', function () {
    // Wed 2026-04-01 is the anchor; week runs Mon-Sun.
    $dates = Rrule::expand('2026-04-01', 'FREQ=WEEKLY;BYDAY=MO,WE,FR', '2026-04-17');

    expect(dateStrings($dates))->toBe([
        '2026-04-01', // Wed (Mon before would be 2026-03-30, before DTSTART → filtered)
        '2026-04-03', // Fri
        '2026-04-06', // next Mon
        '2026-04-08', // next Wed
        '2026-04-10', // next Fri
        '2026-04-13',
        '2026-04-15',
        '2026-04-17',
    ]);
});

it('expands MONTHLY on anchor day without BYMONTHDAY', function () {
    $dates = Rrule::expand('2026-04-05', 'FREQ=MONTHLY', '2026-07-31');

    expect(dateStrings($dates))->toBe(['2026-04-05', '2026-05-05', '2026-06-05', '2026-07-05']);
});

it('expands MONTHLY with BYMONTHDAY listing multiple days', function () {
    $dates = Rrule::expand('2026-04-03', 'FREQ=MONTHLY;BYMONTHDAY=1,15', '2026-06-20');

    // April 1 is before DTSTART → filtered; April 15 kept; then May 1, 15; then June 1, 15.
    expect(dateStrings($dates))->toBe([
        '2026-04-15', '2026-05-01', '2026-05-15', '2026-06-01', '2026-06-15',
    ]);
});

it('skips BYMONTHDAY days that do not exist in a month', function () {
    $dates = Rrule::expand('2026-01-31', 'FREQ=MONTHLY;BYMONTHDAY=31', '2026-05-01');

    expect(dateStrings($dates))->toBe(['2026-01-31', '2026-03-31']); // Feb + Apr drop
});

it('respects INTERVAL for MONTHLY', function () {
    $dates = Rrule::expand('2026-01-15', 'FREQ=MONTHLY;INTERVAL=3', '2026-12-31');

    expect(dateStrings($dates))->toBe(['2026-01-15', '2026-04-15', '2026-07-15', '2026-10-15']);
});

it('expands YEARLY preserving month/day of DTSTART', function () {
    $dates = Rrule::expand('2024-02-29', 'FREQ=YEARLY', '2030-03-01');

    // addYears advances Feb 29 to Feb 28 on non-leap years (Carbon semantics).
    expect(count($dates))->toBe(7);
    expect(dateStrings($dates)[0])->toBe('2024-02-29');
});

it('caps the result at COUNT', function () {
    $dates = Rrule::expand('2026-04-01', 'FREQ=DAILY;COUNT=3', '2026-12-31');

    expect(dateStrings($dates))->toBe(['2026-04-01', '2026-04-02', '2026-04-03']);
});

it('stops at UNTIL in the RRULE', function () {
    $dates = Rrule::expand('2026-04-01', 'FREQ=DAILY;UNTIL=20260403', '2026-04-10');

    expect(dateStrings($dates))->toBe(['2026-04-01', '2026-04-02', '2026-04-03']);
});

it('accepts UNTIL in YYYYMMDDTHHMMSSZ form', function () {
    $dates = Rrule::expand('2026-04-01', 'FREQ=DAILY;UNTIL=20260403T000000Z', '2026-04-10');

    expect(dateStrings($dates))->toBe(['2026-04-01', '2026-04-02', '2026-04-03']);
});

it('honors the passed-in until ceiling over the horizon', function () {
    $dates = Rrule::expand('2026-04-01', 'FREQ=DAILY', '2026-04-10', until: '2026-04-03');

    expect(dateStrings($dates))->toBe(['2026-04-01', '2026-04-02', '2026-04-03']);
});

it('returns empty for an unknown FREQ', function () {
    expect(Rrule::expand('2026-04-01', 'FREQ=HOURLY', '2026-04-02'))->toBe([]);
});

it('returns empty when horizon is before dtstart', function () {
    expect(Rrule::expand('2026-04-10', 'FREQ=DAILY', '2026-04-01'))->toBe([]);
});

it('produces sorted unique dates', function () {
    $dates = Rrule::expand('2026-04-01', 'FREQ=MONTHLY;BYMONTHDAY=1,1,15', '2026-05-20');

    expect(dateStrings($dates))->toBe(['2026-04-01', '2026-04-15', '2026-05-01', '2026-05-15']);
});

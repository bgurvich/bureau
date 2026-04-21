<?php

use App\Models\ChecklistRun;
use App\Models\ChecklistTemplate;
use App\Support\ChecklistScheduling;
use Carbon\CarbonImmutable;

beforeEach(function () {
    authedInHousehold();
});

it('FREQ=DAILY is scheduled on every date at or after dtstart', function () {
    $t = ChecklistTemplate::create([
        'name' => 'D', 'time_of_day' => 'anytime',
        'rrule' => 'FREQ=DAILY',
        'dtstart' => '2026-04-01', 'active' => true,
    ]);

    expect(ChecklistScheduling::isScheduledOn($t, '2026-04-01'))->toBeTrue()
        ->and(ChecklistScheduling::isScheduledOn($t, '2026-04-15'))->toBeTrue()
        ->and(ChecklistScheduling::isScheduledOn($t, '2026-03-31'))->toBeFalse();
});

it('FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR skips Saturdays and Sundays', function () {
    $t = ChecklistTemplate::create([
        'name' => 'Wd', 'time_of_day' => 'morning',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR',
        'dtstart' => '2026-04-01', 'active' => true,
    ]);

    // 2026-04-04 is a Saturday, 2026-04-05 is a Sunday, 2026-04-06 is a Monday.
    expect(ChecklistScheduling::isScheduledOn($t, '2026-04-03'))->toBeTrue()   // Fri
        ->and(ChecklistScheduling::isScheduledOn($t, '2026-04-04'))->toBeFalse() // Sat
        ->and(ChecklistScheduling::isScheduledOn($t, '2026-04-05'))->toBeFalse() // Sun
        ->and(ChecklistScheduling::isScheduledOn($t, '2026-04-06'))->toBeTrue(); // Mon
});

it('a paused_until in the future suppresses scheduling', function () {
    $t = ChecklistTemplate::create([
        'name' => 'P', 'time_of_day' => 'anytime', 'rrule' => 'FREQ=DAILY',
        'dtstart' => '2026-04-01', 'active' => true,
        'paused_until' => '2026-04-30',
    ]);

    expect(ChecklistScheduling::isScheduledOn($t, '2026-04-15'))->toBeFalse()
        ->and(ChecklistScheduling::isScheduledOn($t, '2026-05-01'))->toBeTrue();
});

it('a dtstart in the future suppresses scheduling', function () {
    $t = ChecklistTemplate::create([
        'name' => 'F', 'time_of_day' => 'anytime', 'rrule' => 'FREQ=DAILY',
        'dtstart' => '2026-05-01', 'active' => true,
    ]);

    expect(ChecklistScheduling::isScheduledOn($t, '2026-04-30'))->toBeFalse()
        ->and(ChecklistScheduling::isScheduledOn($t, '2026-05-01'))->toBeTrue();
});

it('null rrule is treated as always scheduled', function () {
    $t = ChecklistTemplate::create([
        'name' => 'N', 'time_of_day' => 'anytime',
        'rrule' => null,
        'dtstart' => '2026-04-01', 'active' => true,
    ]);

    expect(ChecklistScheduling::isScheduledOn($t, '2026-04-01'))->toBeTrue()
        ->and(ChecklistScheduling::isScheduledOn($t, '2026-12-25'))->toBeTrue();
});

it('inactive templates are never scheduled', function () {
    $t = ChecklistTemplate::create([
        'name' => 'X', 'time_of_day' => 'anytime', 'rrule' => 'FREQ=DAILY',
        'dtstart' => '2026-04-01', 'active' => false,
    ]);

    expect(ChecklistScheduling::isScheduledOn($t, '2026-04-15'))->toBeFalse();
});

it('streak counts consecutive completed runs on scheduled days and breaks on miss', function () {
    $today = CarbonImmutable::parse('2026-04-20');

    $t = ChecklistTemplate::create([
        'name' => 'S', 'time_of_day' => 'anytime',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR',
        'dtstart' => '2026-04-01', 'active' => true,
    ]);

    // 2026-04-20 is Monday. Going back: Mon 4/20, Fri 4/17, Thu 4/16, Wed 4/15.
    // Weekends (4/18, 4/19) don't break the streak.
    foreach (['2026-04-20', '2026-04-17', '2026-04-16'] as $d) {
        ChecklistRun::create([
            'checklist_template_id' => $t->id,
            'run_date' => $d,
            'ticked_item_ids' => [],
            'completed_at' => $today,
        ]);
    }
    // Wed 4/15 missed (no run) → streak stops at 3.

    expect(ChecklistScheduling::streak($t, $today))->toBe(3);
});

it('streak skips non-scheduled days without breaking', function () {
    $today = CarbonImmutable::parse('2026-04-20'); // Monday

    $t = ChecklistTemplate::create([
        'name' => 'Sk', 'time_of_day' => 'anytime',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
        'dtstart' => '2026-04-01', 'active' => true,
    ]);

    // Complete the last two Mondays (4/20 + 4/13).
    foreach (['2026-04-20', '2026-04-13'] as $d) {
        ChecklistRun::create([
            'checklist_template_id' => $t->id,
            'run_date' => $d,
            'ticked_item_ids' => [],
            'completed_at' => $today,
        ]);
    }

    expect(ChecklistScheduling::streak($t, $today))->toBe(2);
});

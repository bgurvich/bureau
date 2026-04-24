<?php

declare(strict_types=1);

use App\Models\RadarSnooze;
use App\Models\Task;
use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('snoozeSignal writes a row with the right snoozed_until', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    authedInHousehold();

    Livewire::test('attention-radar')->call('snoozeSignal', 'overdue_tasks', 7);

    $row = RadarSnooze::where('signal_kind', 'overdue_tasks')->firstOrFail();
    expect($row->snoozed_until->toDateTimeString())->toBe('2026-04-30 10:00:00');
});

it('snoozed signals drop out of total()', function () {
    authedInHousehold();
    Task::create(['title' => 'Overdue', 'state' => 'open', 'due_at' => now()->subDay()]);

    $c = Livewire::test('attention-radar');
    expect($c->get('total'))->toBe(1);

    $c->call('snoozeSignal', 'overdue_tasks', 3);
    expect($c->get('total'))->toBe(0);
});

it('snoozeSignal upserts — second call replaces the first', function () {
    authedInHousehold();

    Livewire::test('attention-radar')
        ->call('snoozeSignal', 'overdue_tasks', 3)
        ->call('snoozeSignal', 'overdue_tasks', 30);

    expect(RadarSnooze::count())->toBe(1);
});

it('unsnoozeSignal removes the row', function () {
    authedInHousehold();

    $c = Livewire::test('attention-radar')
        ->call('snoozeSignal', 'overdue_tasks', 7)
        ->call('unsnoozeSignal', 'overdue_tasks');

    expect(RadarSnooze::count())->toBe(0);
});

it('expired snoozes don\'t hide their signal', function () {
    authedInHousehold();
    Task::create(['title' => 'Overdue', 'state' => 'open', 'due_at' => now()->subDay()]);
    // Manually seed a snooze that's already in the past.
    RadarSnooze::create([
        'user_id' => auth()->id(),
        'household_id' => CurrentHousehold::id(),
        'signal_kind' => 'overdue_tasks',
        'snoozed_until' => now()->subHour(),
    ]);

    $c = Livewire::test('attention-radar');
    expect($c->get('total'))->toBe(1);
});

it('clamps snooze days to [1, 90]', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    authedInHousehold();

    Livewire::test('attention-radar')->call('snoozeSignal', 'overdue_tasks', 9999);
    $row = RadarSnooze::firstOrFail();
    expect($row->snoozed_until->toDateTimeString())->toBe('2026-07-22 10:00:00'); // 90 days out
});

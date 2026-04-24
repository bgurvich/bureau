<?php

declare(strict_types=1);

use App\Models\Integration;
use App\Models\Task;
use App\Support\RadarSeverity;
use Livewire\Livewire;

it('classifies each known signal kind', function () {
    expect(RadarSeverity::of('overdue_bills'))->toBe('critical');
    expect(RadarSeverity::of('overdue_tasks'))->toBe('warn');
    expect(RadarSeverity::of('unreconciled'))->toBe('info');
    expect(RadarSeverity::of('nonsense'))->toBe('warn'); // default
});

it('rank orders critical first, info last', function () {
    expect(RadarSeverity::rank('overdue_bills'))->toBe(0);
    expect(RadarSeverity::rank('overdue_tasks'))->toBe(1);
    expect(RadarSeverity::rank('bills_inbox'))->toBe(2);
});

it('criticalTotal counts only critical-severity signals', function () {
    authedInHousehold();

    // 2 overdue tasks (severity=warn, should NOT contribute to critical)
    Task::create(['title' => 'T1', 'state' => 'open', 'due_at' => now()->subDay()]);
    Task::create(['title' => 'T2', 'state' => 'open', 'due_at' => now()->subDays(2)]);

    // 1 integration in error (severity=critical)
    Integration::create([
        'provider' => 'gmail',
        'kind' => 'mail',
        'status' => 'error',
    ]);

    $c = Livewire::test('attention-radar');
    expect($c->get('total'))->toBeGreaterThanOrEqual(3);
    expect($c->get('criticalTotal'))->toBe(1);
});

it('criticalTotal honors snoozes', function () {
    authedInHousehold();
    Integration::create([
        'provider' => 'gmail',
        'kind' => 'mail',
        'status' => 'error',
    ]);

    $c = Livewire::test('attention-radar');
    expect($c->get('criticalTotal'))->toBe(1);

    $c->call('snoozeSignal', 'integrations_needing_reconnect', 3);
    expect($c->get('criticalTotal'))->toBe(0);
});

<?php

use App\Models\TimeTrackerSession;

it('computes elapsed seconds as accumulated + live segment while running', function () {
    $session = new TimeTrackerSession([
        'started_at' => now()->subSeconds(30),
        'accumulated_seconds' => 120,
        'status' => 'running',
    ]);

    expect($session->elapsedSeconds())->toBeGreaterThanOrEqual(149)
        ->and($session->elapsedSeconds())->toBeLessThanOrEqual(151);
});

it('returns accumulated only when paused', function () {
    $session = new TimeTrackerSession([
        'started_at' => now()->subSeconds(30),
        'paused_at' => now(),
        'accumulated_seconds' => 500,
        'status' => 'paused',
    ]);

    expect($session->elapsedSeconds())->toBe(500);
});

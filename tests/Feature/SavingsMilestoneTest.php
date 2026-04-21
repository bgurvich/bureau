<?php

use App\Models\Reminder;
use App\Models\SavingsGoal;
use App\Support\SavingsMilestoneTracker;

function makeGoal(float $target, float $saved = 0, array $hit = []): SavingsGoal
{
    return SavingsGoal::forceCreate([
        'name' => 'Fund', 'target_amount' => $target, 'saved_amount' => $saved,
        'starting_amount' => 0, 'currency' => 'USD', 'state' => 'active',
        'milestones_hit' => $hit === [] ? null : $hit,
    ]);
}

it('fires a reminder at each newly-crossed milestone', function () {
    authedInHousehold();
    $goal = makeGoal(1000, 260);   // 26% → hits 25

    $count = SavingsMilestoneTracker::checkGoal($goal);
    expect($count)->toBe(1)
        ->and(Reminder::count())->toBe(1);
    expect($goal->fresh()->milestones_hit)->toBe([25]);
});

it('does not re-fire a milestone already recorded', function () {
    authedInHousehold();
    $goal = makeGoal(1000, 260, [25]);

    expect(SavingsMilestoneTracker::checkGoal($goal))->toBe(0);
    expect(Reminder::count())->toBe(0);
});

it('fires multiple milestones in a single sweep when crossed at once', function () {
    authedInHousehold();
    // Jumps from $0 to $800 in one save — crosses 25, 50, 75 at once
    $goal = makeGoal(1000, 800);

    expect(SavingsMilestoneTracker::checkGoal($goal))->toBe(3)
        ->and($goal->fresh()->milestones_hit)->toBe([25, 50, 75]);
});

it('fires 100 when target reached', function () {
    authedInHousehold();
    $goal = makeGoal(1000, 1000);

    expect(SavingsMilestoneTracker::checkGoal($goal))->toBe(4);
    $hit = $goal->fresh()->milestones_hit;
    expect($hit)->toContain(100);
});

it('artisan savings:milestones prints created count', function () {
    authedInHousehold();
    makeGoal(1000, 600);   // 25 + 50

    $this->artisan('savings:milestones')
        ->expectsOutputToContain('Created 2')
        ->assertSuccessful();
});

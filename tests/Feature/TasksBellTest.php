<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('lists only open tasks, sorted by priority then due_at with nulls last', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    authedInHousehold();

    $a = Task::create(['title' => 'Low prio, soon', 'state' => 'open', 'priority' => 5, 'due_at' => '2026-04-24']);
    $b = Task::create(['title' => 'High prio, no date', 'state' => 'open', 'priority' => 1, 'due_at' => null]);
    $c = Task::create(['title' => 'High prio, soon', 'state' => 'open', 'priority' => 1, 'due_at' => '2026-04-25']);
    Task::create(['title' => 'Already done', 'state' => 'done', 'priority' => 1, 'due_at' => '2026-04-24']);

    $titles = Livewire::test('tasks-bell')
        ->get('topTasks')
        ->pluck('id')
        ->all();

    // Priority 1 first (due date asc with nulls last), then priority 5.
    expect($titles)->toBe([$c->id, $b->id, $a->id]);
});

it('counts tasks due today or overdue in the acute badge', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    authedInHousehold();

    Task::create(['title' => 'Overdue', 'state' => 'open', 'due_at' => '2026-04-20 10:00:00']);
    Task::create(['title' => 'Due today', 'state' => 'open', 'due_at' => '2026-04-23 18:00:00']);
    Task::create(['title' => 'Future', 'state' => 'open', 'due_at' => '2026-05-01']);
    Task::create(['title' => 'No date', 'state' => 'open', 'due_at' => null]);
    Task::create(['title' => 'Done overdue', 'state' => 'done', 'due_at' => '2026-04-20']);

    $c = Livewire::test('tasks-bell');
    expect($c->get('acuteCount'))->toBe(2);
    expect($c->get('openCount'))->toBe(4);
});

it('eager-loads each task\'s project for the row subtitle', function () {
    authedInHousehold();
    $p = Project::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $t = Task::create(['title' => 'Pick a frontend framework', 'state' => 'open', 'project_id' => $p->id]);

    $rows = Livewire::test('tasks-bell')->get('topTasks');
    $row = $rows->firstWhere('id', $t->id);
    expect($row->project?->name)->toBe('Alpha');
});

it('caps the list at 10 rows', function () {
    authedInHousehold();
    for ($i = 0; $i < 15; $i++) {
        Task::create(['title' => "Task $i", 'state' => 'open', 'priority' => 3]);
    }

    $top = Livewire::test('tasks-bell')->get('topTasks');
    expect($top)->toHaveCount(10);
});

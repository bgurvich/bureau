<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\Project;
use App\Models\Task;
use Livewire\Livewire;

it('filters tasks by project_id', function () {
    authedInHousehold();
    $a = Project::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $b = Project::create(['name' => 'Beta', 'slug' => 'beta']);
    Task::create(['title' => 'A1', 'state' => 'open', 'project_id' => $a->id]);
    Task::create(['title' => 'B1', 'state' => 'open', 'project_id' => $b->id]);
    Task::create(['title' => 'Orphan', 'state' => 'open']);

    $c = Livewire::test('tasks-tree')
        ->set('projectFilter', (string) $a->id);
    expect($c->get('tasks')->pluck('title')->all())->toBe(['A1']);
});

it('filters tasks by goal (via project membership)', function () {
    authedInHousehold();
    $goal = Goal::create(['title' => 'Ship v1', 'mode' => 'target', 'status' => 'active', 'category' => 'work']);
    $a = Project::create(['name' => 'Alpha', 'slug' => 'alpha', 'goal_id' => $goal->id]);
    $b = Project::create(['name' => 'Beta', 'slug' => 'beta']);
    Task::create(['title' => 'A1', 'state' => 'open', 'project_id' => $a->id]);
    Task::create(['title' => 'B1', 'state' => 'open', 'project_id' => $b->id]);
    Task::create(['title' => 'Orphan', 'state' => 'open']);

    $c = Livewire::test('tasks-tree')
        ->set('goalFilter', (string) $goal->id);
    expect($c->get('tasks')->pluck('title')->all())->toBe(['A1']);
});

it('createGoal inserts and pre-selects the new goal', function () {
    authedInHousehold();

    $c = Livewire::test('tasks-tree')
        ->call('createGoal', 'Get fit');

    $goal = Goal::where('title', 'Get fit')->firstOrFail();
    expect($c->get('goalFilter'))->toBe((string) $goal->id);
});

it('createProject inherits the active goal filter when one is set', function () {
    authedInHousehold();
    $goal = Goal::create(['title' => 'Ship v1', 'mode' => 'target', 'status' => 'active', 'category' => 'work']);

    $c = Livewire::test('tasks-tree')
        ->set('goalFilter', (string) $goal->id)
        ->call('createProject', 'New project');

    $project = Project::where('name', 'New project')->firstOrFail();
    expect($project->goal_id)->toBe($goal->id);
    expect($c->get('projectFilter'))->toBe((string) $project->id);
});

<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Task;
use Livewire\Livewire;

it('renders the tree page', function () {
    authedInHousehold();

    $this->get(route('calendar.tasks.tree'))
        ->assertOk()
        ->assertSeeText('Tasks tree');
});

it('groups tasks by project with an Unassigned bucket', function () {
    authedInHousehold();
    $proj = Project::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $a = Task::create(['title' => 'A', 'state' => 'open', 'project_id' => $proj->id]);
    $b = Task::create(['title' => 'B', 'state' => 'open']);

    $grouped = Livewire::test('tasks-tree')->get('groupedTree');

    $key = 'project:'.$proj->id;
    expect($grouped)->toHaveKey($key);
    expect($grouped[$key]->pluck('task.id')->all())->toBe([$a->id]);
    expect($grouped['unassigned']->pluck('task.id')->all())->toBe([$b->id]);
});

it('nests subtasks under their parent with incrementing depth', function () {
    authedInHousehold();
    $proj = Project::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $root = Task::create(['title' => 'Root', 'state' => 'open', 'project_id' => $proj->id]);
    $child = Task::create(['title' => 'Child', 'state' => 'open', 'project_id' => $proj->id, 'parent_task_id' => $root->id]);
    $grand = Task::create(['title' => 'Grand', 'state' => 'open', 'project_id' => $proj->id, 'parent_task_id' => $child->id]);

    $grouped = Livewire::test('tasks-tree')->get('groupedTree');

    $rows = $grouped['project:'.$proj->id]->all();
    expect($rows)->toHaveCount(3);
    expect($rows[0]['task']->id)->toBe($root->id);
    expect($rows[0]['depth'])->toBe(0);
    expect($rows[1]['task']->id)->toBe($child->id);
    expect($rows[1]['depth'])->toBe(1);
    expect($rows[2]['task']->id)->toBe($grand->id);
    expect($rows[2]['depth'])->toBe(2);
});

it('excludes done tasks from the tree', function () {
    authedInHousehold();
    Task::create(['title' => 'Open', 'state' => 'open']);
    Task::create(['title' => 'Done', 'state' => 'done']);

    $grouped = Livewire::test('tasks-tree')->get('groupedTree');

    expect($grouped['unassigned']->pluck('task.title')->all())->toBe(['Open']);
});

it('toggle() flips state and refreshes the tree', function () {
    authedInHousehold();
    $t = Task::create(['title' => 'Flip me', 'state' => 'open']);

    Livewire::test('tasks-tree')->call('toggle', $t->id);

    expect($t->fresh()->state)->toBe('done');
});

it('task inspector saves project_id', function () {
    authedInHousehold();
    $proj = Project::create(['name' => 'Alpha', 'slug' => 'alpha']);

    Livewire::test('inspector.task-form')
        ->set('title', 'Build stuff')
        ->set('project_id', $proj->id)
        ->call('save');

    $task = Task::where('title', 'Build stuff')->firstOrFail();
    expect($task->project_id)->toBe($proj->id);
});

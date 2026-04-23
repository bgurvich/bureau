<?php

use App\Models\Task;
use Livewire\Livewire;

it('saves a task with a parent_task_id set', function () {
    authedInHousehold();
    $parent = Task::create(['title' => 'Parent task', 'state' => 'open']);

    Livewire::test('inspector.task-form')
        ->set('title', 'Child task')
        ->set('parent_task_id', $parent->id)
        ->call('save');

    $child = Task::where('title', 'Child task')->firstOrFail();
    expect($child->parent_task_id)->toBe($parent->id)
        ->and($parent->children()->pluck('id')->all())->toBe([$child->id]);
});

it('pre-fills parent when mounted with parentId (add-subtask flow)', function () {
    authedInHousehold();
    $parent = Task::create(['title' => 'Parent', 'state' => 'open']);

    Livewire::test('inspector.task-form', ['parentId' => $parent->id])
        ->assertSet('parent_task_id', $parent->id);
});

it('rejects self-parent on edit', function () {
    authedInHousehold();
    $task = Task::create(['title' => 'Self', 'state' => 'open']);

    Livewire::test('inspector.task-form', ['id' => $task->id])
        ->set('parent_task_id', $task->id)
        ->call('save')
        ->assertHasErrors(['parent_task_id']);

    expect($task->fresh()->parent_task_id)->toBeNull();
});

it('rejects picking a descendant as the parent (cycle prevention)', function () {
    authedInHousehold();
    $a = Task::create(['title' => 'A', 'state' => 'open']);
    $b = Task::create(['title' => 'B', 'state' => 'open', 'parent_task_id' => $a->id]);
    $c = Task::create(['title' => 'C', 'state' => 'open', 'parent_task_id' => $b->id]);

    // Try to make A a child of C (C is a descendant of A — would cycle).
    Livewire::test('inspector.task-form', ['id' => $a->id])
        ->set('parent_task_id', $c->id)
        ->call('save')
        ->assertHasErrors(['parent_task_id']);
});

it('parent picker excludes the task itself + its descendants', function () {
    authedInHousehold();
    $a = Task::create(['title' => 'A', 'state' => 'open']);
    $b = Task::create(['title' => 'B', 'state' => 'open', 'parent_task_id' => $a->id]);
    Task::create(['title' => 'Unrelated', 'state' => 'open']);

    $c = Livewire::test('inspector.task-form', ['id' => $a->id]);
    $options = $c->get('parentTaskPickerOptions');

    // A itself + its descendant B must be absent; unrelated survives.
    expect($options)->not->toHaveKey($a->id)
        ->and($options)->not->toHaveKey($b->id)
        ->and($options)->toHaveCount(1);
});

it('parent picker only surfaces open/waiting tasks', function () {
    authedInHousehold();
    Task::create(['title' => 'Open', 'state' => 'open']);
    Task::create(['title' => 'Waiting', 'state' => 'waiting']);
    Task::create(['title' => 'Done', 'state' => 'done']);
    Task::create(['title' => 'Dropped', 'state' => 'dropped']);

    $c = Livewire::test('inspector.task-form');
    $titles = array_values($c->get('parentTaskPickerOptions'));
    expect($titles)->toEqualCanonicalizing(['Open', 'Waiting']);
});

it('tasks-index taskTree indents subtasks under their parents', function () {
    authedInHousehold();
    $a = Task::create(['title' => 'Root A', 'state' => 'open', 'priority' => 1]);
    $b = Task::create(['title' => 'Sub of A', 'state' => 'open', 'parent_task_id' => $a->id, 'priority' => 2]);
    $c = Task::create(['title' => 'Root C', 'state' => 'open', 'priority' => 3]);

    $tree = Livewire::test('tasks-index')->get('taskTree');

    expect($tree)->toHaveCount(3);
    expect($tree[0]['task']->id)->toBe($a->id)
        ->and($tree[0]['depth'])->toBe(0);
    expect($tree[1]['task']->id)->toBe($b->id)
        ->and($tree[1]['depth'])->toBe(1);
    expect($tree[2]['task']->id)->toBe($c->id)
        ->and($tree[2]['depth'])->toBe(0);
});

it('orphan children (parent filtered out) still render at depth 0', function () {
    authedInHousehold();
    $parent = Task::create(['title' => 'Parent', 'state' => 'done']);
    $child = Task::create(['title' => 'Child', 'state' => 'open', 'parent_task_id' => $parent->id]);

    // State=open filter excludes the parent; child should still surface.
    $tree = Livewire::test('tasks-index')
        ->set('stateFilter', 'open')
        ->get('taskTree');

    expect($tree)->toHaveCount(1);
    expect($tree[0]['task']->id)->toBe($child->id)
        ->and($tree[0]['depth'])->toBe(0);
});

<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\Project;
use App\Models\Task;
use Livewire\Livewire;

it('moves a task between projects and rewrites position in the target', function () {
    authedInHousehold();
    $a = Project::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $b = Project::create(['name' => 'Beta', 'slug' => 'beta']);

    $t1 = Task::create(['title' => 'T1', 'state' => 'open', 'project_id' => $a->id, 'position' => 0]);
    $t2 = Task::create(['title' => 'T2', 'state' => 'open', 'project_id' => $a->id, 'position' => 1]);
    $t3 = Task::create(['title' => 'T3', 'state' => 'open', 'project_id' => $b->id, 'position' => 0]);

    // Drop t1 at the top of project B → B becomes [t1, t3].
    Livewire::test('tasks-tree')
        ->call('moveToGroup', 'project:'.$b->id, [$t1->id, $t3->id]);

    expect($t1->fresh()->project_id)->toBe($b->id);
    expect($t1->fresh()->position)->toBe(0);
    expect($t3->fresh()->position)->toBe(1);
    // T2 stays in project A untouched.
    expect($t2->fresh()->project_id)->toBe($a->id);
});

it('accepts the unassigned group key to strip project_id', function () {
    authedInHousehold();
    $p = Project::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $t = Task::create(['title' => 'Drift', 'state' => 'open', 'project_id' => $p->id]);

    Livewire::test('tasks-tree')->call('moveToGroup', 'unassigned', [$t->id]);

    expect($t->fresh()->project_id)->toBeNull();
});

it('refuses to move subtasks (only top-level rows have drag handles)', function () {
    authedInHousehold();
    $p = Project::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $root = Task::create(['title' => 'Root', 'state' => 'open', 'project_id' => $p->id]);
    $sub = Task::create(['title' => 'Sub', 'state' => 'open', 'parent_task_id' => $root->id, 'project_id' => $p->id]);

    Livewire::test('tasks-tree')->call('moveToGroup', 'unassigned', [$sub->id]);

    // Subtask project_id was NOT cleared because moveToGroup restricts
    // to parent_task_id IS NULL rows.
    expect($sub->fresh()->project_id)->toBe($p->id);
});

it('ignores a group key that does not resolve to a real project', function () {
    authedInHousehold();
    $p = Project::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $t = Task::create(['title' => 'T', 'state' => 'open', 'project_id' => $p->id]);

    Livewire::test('tasks-tree')->call('moveToGroup', 'project:999999', [$t->id]);

    expect($t->fresh()->project_id)->toBe($p->id);
});

it('moveProjectToGoal reassigns goal_id', function () {
    authedInHousehold();
    $g1 = Goal::create(['title' => 'G1', 'mode' => 'direction', 'status' => 'active', 'category' => 'other']);
    $g2 = Goal::create(['title' => 'G2', 'mode' => 'direction', 'status' => 'active', 'category' => 'other']);
    $p = Project::create(['name' => 'Alpha', 'slug' => 'alpha', 'goal_id' => $g1->id]);

    Livewire::test('tasks-tree')->call('moveProjectToGoal', $p->id, 'goal:'.$g2->id);

    expect($p->fresh()->goal_id)->toBe($g2->id);
});

it('moveProjectToGoal accepts no-goal to clear goal_id', function () {
    authedInHousehold();
    $g = Goal::create(['title' => 'G1', 'mode' => 'direction', 'status' => 'active', 'category' => 'other']);
    $p = Project::create(['name' => 'Alpha', 'slug' => 'alpha', 'goal_id' => $g->id]);

    Livewire::test('tasks-tree')->call('moveProjectToGoal', $p->id, 'no-goal');

    expect($p->fresh()->goal_id)->toBeNull();
});

it('moveProjectToGoal ignores a goal that doesn\'t exist', function () {
    authedInHousehold();
    $g = Goal::create(['title' => 'G1', 'mode' => 'direction', 'status' => 'active', 'category' => 'other']);
    $p = Project::create(['name' => 'Alpha', 'slug' => 'alpha', 'goal_id' => $g->id]);

    Livewire::test('tasks-tree')->call('moveProjectToGoal', $p->id, 'goal:999999');

    expect($p->fresh()->goal_id)->toBe($g->id);
});

it('nestUnder sets parent_task_id + inherits parent project_id', function () {
    authedInHousehold();
    $p1 = Project::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $p2 = Project::create(['name' => 'Beta', 'slug' => 'beta']);
    $parent = Task::create(['title' => 'Parent', 'state' => 'open', 'project_id' => $p1->id]);
    $child = Task::create(['title' => 'Child', 'state' => 'open', 'project_id' => $p2->id]);

    Livewire::test('tasks-tree')->call('nestUnder', $child->id, $parent->id);

    $child->refresh();
    expect($child->parent_task_id)->toBe($parent->id);
    expect($child->project_id)->toBe($p1->id);
});

it('nestUnder refuses a self-parent', function () {
    authedInHousehold();
    $t = Task::create(['title' => 'T', 'state' => 'open']);

    Livewire::test('tasks-tree')->call('nestUnder', $t->id, $t->id);

    expect($t->fresh()->parent_task_id)->toBeNull();
});

it('nestUnder refuses a cycle (candidate parent is a descendant)', function () {
    authedInHousehold();
    $root = Task::create(['title' => 'Root', 'state' => 'open']);
    $sub = Task::create(['title' => 'Sub', 'state' => 'open', 'parent_task_id' => $root->id]);

    // Trying to make root a child of sub would close a cycle.
    Livewire::test('tasks-tree')->call('nestUnder', $root->id, $sub->id);

    expect($root->fresh()->parent_task_id)->toBeNull();
});

it('reorders within a project', function () {
    authedInHousehold();
    $p = Project::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $t1 = Task::create(['title' => 'T1', 'state' => 'open', 'project_id' => $p->id, 'position' => 0]);
    $t2 = Task::create(['title' => 'T2', 'state' => 'open', 'project_id' => $p->id, 'position' => 1]);
    $t3 = Task::create(['title' => 'T3', 'state' => 'open', 'project_id' => $p->id, 'position' => 2]);

    Livewire::test('tasks-tree')
        ->call('moveToGroup', 'project:'.$p->id, [$t3->id, $t1->id, $t2->id]);

    expect($t3->fresh()->position)->toBe(0);
    expect($t1->fresh()->position)->toBe(1);
    expect($t2->fresh()->position)->toBe(2);
});

<?php

declare(strict_types=1);

use App\Models\Task;
use Livewire\Livewire;

it('isBlocked is true when any predecessor is not done', function () {
    authedInHousehold();
    $a = Task::create(['title' => 'A', 'state' => 'open']);
    $b = Task::create(['title' => 'B', 'state' => 'open']);
    $b->predecessors()->attach($a->id);

    expect($b->fresh()->isBlocked())->toBeTrue();

    $a->update(['state' => 'done', 'completed_at' => now()]);

    expect($b->fresh()->isBlocked())->toBeFalse();
});

it('isBlocked with multiple predecessors requires all done', function () {
    authedInHousehold();
    $a = Task::create(['title' => 'A', 'state' => 'open']);
    $b = Task::create(['title' => 'B', 'state' => 'open']);
    $c = Task::create(['title' => 'C', 'state' => 'open']);
    $c->predecessors()->attach([$a->id, $b->id]);

    $a->update(['state' => 'done']);
    expect($c->fresh()->isBlocked())->toBeTrue();

    $b->update(['state' => 'done']);
    expect($c->fresh()->isBlocked())->toBeFalse();
});

it('addDependency / removeDependency rewrites the array', function () {
    authedInHousehold();
    $a = Task::create(['title' => 'A', 'state' => 'open']);
    $b = Task::create(['title' => 'B', 'state' => 'open']);

    $c = Livewire::test('inspector.task-form', ['id' => $b->id])
        ->call('addDependency', $a->id)
        ->assertSet('depends_on_task_ids', [$a->id])
        ->call('addDependency', $a->id) // dedup
        ->assertSet('depends_on_task_ids', [$a->id])
        ->call('removeDependency', $a->id)
        ->assertSet('depends_on_task_ids', []);

    expect($c)->not->toBeNull();
});

it('rejects self-dependency on save', function () {
    authedInHousehold();
    $t = Task::create(['title' => 'T', 'state' => 'open']);

    Livewire::test('inspector.task-form', ['id' => $t->id])
        ->set('depends_on_task_ids', [$t->id])
        ->call('save')
        ->assertHasErrors(['depends_on_task_ids.0']);
});

it('rejects a cycle (A depends on B, then B tries to depend on A)', function () {
    authedInHousehold();
    $a = Task::create(['title' => 'A', 'state' => 'open']);
    $b = Task::create(['title' => 'B', 'state' => 'open']);
    $a->predecessors()->attach($b->id); // A waits on B

    Livewire::test('inspector.task-form', ['id' => $b->id])
        ->set('depends_on_task_ids', [$a->id])
        ->call('save')
        ->assertHasErrors(['depends_on_task_ids.0']);
});

it('persists predecessors through save', function () {
    authedInHousehold();
    $a = Task::create(['title' => 'A', 'state' => 'open']);
    $b = Task::create(['title' => 'B', 'state' => 'open']);

    Livewire::test('inspector.task-form', ['id' => $b->id])
        ->set('depends_on_task_ids', [$a->id])
        ->call('save');

    expect($b->fresh()->predecessors->pluck('id')->all())->toBe([$a->id]);
});

<?php

use App\Models\Goal;
use App\Models\Project;
use App\Models\Task;
use Livewire\Livewire;

it('creates a target-mode goal with a pacing target date', function () {
    authedInHousehold();

    Livewire::test('inspector.goal-form')
        ->set('title', 'Read 20 books')
        ->set('category', 'learning')
        ->set('mode', 'target')
        ->set('target_value', '20')
        ->set('current_value', '3')
        ->set('unit', 'books')
        ->set('started_on', '2026-01-01')
        ->set('target_date', '2026-12-31')
        ->call('save');

    $g = Goal::firstOrFail();
    expect($g->title)->toBe('Read 20 books')
        ->and($g->mode)->toBe('target')
        ->and((float) $g->target_value)->toBe(20.0)
        ->and((float) $g->current_value)->toBe(3.0)
        ->and($g->unit)->toBe('books')
        ->and($g->target_date?->toDateString())->toBe('2026-12-31')
        ->and($g->cadence_days)->toBeNull();
});

it('creates a direction-mode goal with a cadence and stamps last_reflected_at', function () {
    authedInHousehold();

    Livewire::test('inspector.goal-form')
        ->set('title', 'Read more')
        ->set('category', 'learning')
        ->set('mode', 'direction')
        ->set('cadence_days', '7')
        ->call('save');

    $g = Goal::firstOrFail();
    expect($g->mode)->toBe('direction')
        ->and($g->target_value)->toBeNull()
        ->and($g->cadence_days)->toBe(7)
        ->and($g->last_reflected_at)->not->toBeNull();
});

it('switching mode target->direction clears the target-side values', function () {
    authedInHousehold();

    $c = Livewire::test('inspector.goal-form')
        ->set('title', 'Run 500 miles')
        ->set('category', 'health')
        ->set('mode', 'target')
        ->set('target_value', '500')
        ->set('current_value', '120')
        ->set('target_date', '2026-12-31')
        ->call('save');

    $id = $c->get('id');

    Livewire::test('inspector.goal-form', ['id' => $id])
        ->set('mode', 'direction')
        ->set('cadence_days', '14')
        ->call('save');

    $g = Goal::find($id);
    expect($g->mode)->toBe('direction')
        ->and($g->target_value)->toBeNull()
        ->and($g->target_date)->toBeNull()
        ->and((float) $g->current_value)->toBe(0.0)
        ->and($g->cadence_days)->toBe(14);
});

it('stamps achieved_on when status goes to achieved and clears it on reversal', function () {
    authedInHousehold();

    $c = Livewire::test('inspector.goal-form')
        ->set('title', 'Learn Spanish')
        ->set('category', 'learning')
        ->set('mode', 'target')
        ->set('target_value', '100')
        ->set('current_value', '100')
        ->set('status', 'achieved')
        ->call('save');

    $g = Goal::find($c->get('id'));
    expect($g->achieved_on)->not->toBeNull();

    Livewire::test('inspector.goal-form', ['id' => $g->id])
        ->set('status', 'active')
        ->call('save');

    expect(Goal::find($g->id)->achieved_on)->toBeNull();
});

it('rejects target_date earlier than started_on', function () {
    authedInHousehold();

    Livewire::test('inspector.goal-form')
        ->set('title', 'Too soon')
        ->set('category', 'other')
        ->set('mode', 'target')
        ->set('target_value', '10')
        ->set('current_value', '0')
        ->set('started_on', '2026-06-01')
        ->set('target_date', '2026-01-01')
        ->call('save')
        ->assertHasErrors(['target_date']);

    expect(Goal::count())->toBe(0);
});

it('Goal model progress() clamps at 1.0 and onTrack() reads elapsed vs progress', function () {
    $g = new Goal([
        'target_value' => 100,
        'current_value' => 120, // over-hit
    ]);
    expect($g->progress())->toBe(1.0);

    $behind = new Goal([
        'target_value' => 100,
        'current_value' => 10,
        'started_on' => now()->subDays(90),
        'target_date' => now()->addDays(10),
    ]);
    expect($behind->onTrack())->toBeFalse();

    $ahead = new Goal([
        'target_value' => 100,
        'current_value' => 90,
        'started_on' => now()->subDays(10),
        'target_date' => now()->addDays(90),
    ]);
    expect($ahead->onTrack())->toBeTrue();

    $noDate = new Goal(['target_value' => 100, 'current_value' => 5]);
    expect($noDate->onTrack())->toBeNull();
});

it('direction Goal::isStale() is true when cadence has elapsed', function () {
    $stale = new Goal([
        'mode' => 'direction',
        'cadence_days' => 7,
        'last_reflected_at' => now()->subDays(30),
    ]);
    expect($stale->isStale())->toBeTrue();

    $fresh = new Goal([
        'mode' => 'direction',
        'cadence_days' => 7,
        'last_reflected_at' => now()->subDays(2),
    ]);
    expect($fresh->isStale())->toBeFalse();

    $noCadence = new Goal(['mode' => 'direction', 'cadence_days' => null]);
    expect($noCadence->isStale())->toBeNull();

    $neverReflected = new Goal([
        'mode' => 'direction',
        'cadence_days' => 7,
        'last_reflected_at' => null,
    ]);
    expect($neverReflected->isStale())->toBeTrue();
});

it('Goal can be linked as a subject from a Task', function () {
    authedInHousehold();
    $goal = Goal::create([
        'title' => 'Run 500 miles',
        'category' => 'health',
        'mode' => 'target',
        'target_value' => 500,
        'current_value' => 0,
        'status' => 'active',
    ]);

    Livewire::test('inspector.task-form')
        ->set('title', 'Morning run')
        ->set('subject_refs', ['goal:'.$goal->id])
        ->call('save');

    $task = Task::firstOrFail();
    expect($task->subjects()->pluck('id')->all())->toContain($goal->id);
});

it('Goal can be linked as a subject from a Project', function () {
    authedInHousehold();
    $goal = Goal::create([
        'title' => 'Learn more design',
        'category' => 'learning',
        'mode' => 'direction',
        'current_value' => 0,
        'status' => 'active',
    ]);

    Livewire::test('inspector.project-form')
        ->set('project_name', 'Portfolio site')
        ->set('subject_refs', ['goal:'.$goal->id])
        ->call('save');

    $project = Project::firstOrFail();
    expect($project->subjects()->pluck('id')->all())->toContain($goal->id);
});

it('goals index filters by status and mode independently', function () {
    authedInHousehold();

    Goal::create(['title' => 'Active target', 'category' => 'other', 'mode' => 'target', 'target_value' => 10, 'current_value' => 0, 'status' => 'active']);
    Goal::create(['title' => 'Active direction', 'category' => 'other', 'mode' => 'direction', 'current_value' => 0, 'status' => 'active']);
    Goal::create(['title' => 'Achieved target', 'category' => 'other', 'mode' => 'target', 'target_value' => 10, 'current_value' => 10, 'status' => 'achieved']);
    Goal::create(['title' => 'Paused direction', 'category' => 'other', 'mode' => 'direction', 'current_value' => 0, 'status' => 'paused']);

    $c = Livewire::test('goals-index');
    // Default filter = active
    expect($c->get('goals')->pluck('title')->all())->toEqualCanonicalizing(['Active target', 'Active direction']);

    $c->set('statusFilter', '');
    expect($c->get('goals')->count())->toBe(4);

    $c->set('modeFilter', 'direction');
    expect($c->get('goals')->pluck('title')->all())->toEqualCanonicalizing(['Active direction', 'Paused direction']);

    $c->set('statusFilter', 'paused');
    expect($c->get('goals')->pluck('title')->all())->toBe(['Paused direction']);
});

it('goals index surfaces linked-task progress counts', function () {
    authedInHousehold();

    $goal = Goal::create(['title' => 'Run 500 mi', 'category' => 'health', 'mode' => 'target', 'target_value' => 500, 'current_value' => 0, 'status' => 'active']);

    // 2 tasks linked, 1 done
    $open = Task::create(['title' => 'Week 1 runs', 'state' => 'open']);
    $done = Task::create(['title' => 'Week 2 runs', 'state' => 'done']);
    $open->syncSubjects([['type' => Goal::class, 'id' => $goal->id]]);
    $done->syncSubjects([['type' => Goal::class, 'id' => $goal->id]]);

    $c = Livewire::test('goals-index');
    $counts = $c->get('linkedTaskCounts');
    expect($counts[$goal->id])->toBe(['total' => 2, 'done' => 1]);
});

it('Attention radar counts active target goals behind pace', function () {
    authedInHousehold();

    // Behind pace: 90 days in of a 100-day goal, 10% of target hit
    Goal::create([
        'title' => 'Behind',
        'category' => 'health',
        'mode' => 'target',
        'target_value' => 100,
        'current_value' => 10,
        'started_on' => now()->subDays(90)->toDateString(),
        'target_date' => now()->addDays(10)->toDateString(),
        'status' => 'active',
    ]);

    // On pace
    Goal::create([
        'title' => 'On pace',
        'category' => 'health',
        'mode' => 'target',
        'target_value' => 100,
        'current_value' => 90,
        'started_on' => now()->subDays(90)->toDateString(),
        'target_date' => now()->addDays(10)->toDateString(),
        'status' => 'active',
    ]);

    // Paused (status) — excluded
    Goal::create([
        'title' => 'Paused',
        'category' => 'health',
        'mode' => 'target',
        'target_value' => 100,
        'current_value' => 5,
        'started_on' => now()->subDays(90)->toDateString(),
        'target_date' => now()->addDays(10)->toDateString(),
        'status' => 'paused',
    ]);

    $c = Livewire::test('attention-radar');
    expect($c->get('goalsBehindPace'))->toBe(1);
});

it('Attention radar counts active direction goals that are stale', function () {
    authedInHousehold();

    // Stale (last reflected 30d ago, cadence 7d)
    Goal::create([
        'title' => 'Stale',
        'category' => 'health',
        'mode' => 'direction',
        'cadence_days' => 7,
        'last_reflected_at' => now()->subDays(30),
        'status' => 'active',
    ]);

    // Fresh
    Goal::create([
        'title' => 'Fresh',
        'category' => 'health',
        'mode' => 'direction',
        'cadence_days' => 7,
        'last_reflected_at' => now()->subDays(2),
        'status' => 'active',
    ]);

    // Never reflected — counts as stale
    Goal::create([
        'title' => 'Never',
        'category' => 'health',
        'mode' => 'direction',
        'cadence_days' => 7,
        'status' => 'active',
    ]);

    // No cadence — excluded
    Goal::create([
        'title' => 'No nudge',
        'category' => 'health',
        'mode' => 'direction',
        'status' => 'active',
    ]);

    $c = Livewire::test('attention-radar');
    expect($c->get('goalsStale'))->toBe(2);
});

it('goal with a subject can be linked back via the goal model', function () {
    authedInHousehold();

    $goal = Goal::create(['title' => 'Civic upkeep', 'category' => 'other', 'mode' => 'direction', 'current_value' => 0, 'status' => 'active']);
    $task = Task::create(['title' => 'Rotate tires', 'state' => 'open']);
    $task->syncSubjects([['type' => Goal::class, 'id' => $goal->id]]);

    expect($goal->linkedTasks()->pluck('id')->all())->toBe([$task->id]);
});

<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Goal;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('creates one task per non-empty line with tags + dates', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    authedInHousehold();

    Livewire::test('tasks-index')
        ->set('bulkOpen', true)
        ->set('bulkInput', "Pick up dry cleaning #errands by 5/3\n\nBook dentist by 6/15 #health")
        ->call('bulkSave')
        ->assertSet('bulkInput', '');

    expect(Task::count())->toBe(2);

    $first = Task::where('title', 'Pick up dry cleaning')->firstOrFail();
    expect($first->due_at?->toDateTimeString())->toBe('2026-05-03 09:00:00');
    expect($first->tags->pluck('name')->all())->toBe(['errands']);

    $second = Task::where('title', 'Book dentist')->firstOrFail();
    expect($second->due_at?->toDateTimeString())->toBe('2026-06-15 09:00:00');
    expect($second->tags->pluck('name')->all())->toBe(['health']);

    expect(Tag::pluck('slug')->sort()->values()->all())->toBe(['errands', 'health']);
});

it('links @contact matches via subjects and reports unmatched ones', function () {
    authedInHousehold();
    Contact::create(['display_name' => 'Alice Johnson']);

    $c = Livewire::test('tasks-index')
        ->set('bulkOpen', true)
        ->set('bulkInput', "Call @alice about taxes\nEmail @missingperson")
        ->call('bulkSave');

    $linked = Task::where('title', 'Call about taxes')->firstOrFail();
    $subjects = $linked->subjects();
    expect($subjects)->toHaveCount(1);
    expect($subjects->first()->display_name)->toBe('Alice Johnson');

    $notes = $c->get('bulkNotes');
    expect($notes)->toContain('Added 2 task(s).');
    expect(collect($notes)->contains(fn ($n) => str_contains($n, '@missingperson')))->toBeTrue();
});

it('uses parsed P1..P5 priority when present, defaults to 3 otherwise', function () {
    authedInHousehold();

    Livewire::test('tasks-index')
        ->set('bulkOpen', true)
        ->set('bulkInput', "Fix critical bug P1\nTidy garage")
        ->call('bulkSave');

    expect(Task::where('title', 'Fix critical bug')->value('priority'))->toBe(1);
    expect(Task::where('title', 'Tidy garage')->value('priority'))->toBe(3);
});

it('applies the bulk goal + project pickers to every new task', function () {
    authedInHousehold();
    $goal = Goal::create(['title' => 'Ship v1', 'mode' => 'target', 'status' => 'active', 'category' => 'work']);
    $project = Project::create(['name' => 'Alpha', 'slug' => 'alpha']);

    Livewire::test('tasks-index')
        ->set('bulkOpen', true)
        ->set('bulkGoalId', $goal->id)
        ->set('bulkProjectId', $project->id)
        ->set('bulkInput', "Write README\nShip deploy")
        ->call('bulkSave');

    $tasks = Task::whereIn('title', ['Write README', 'Ship deploy'])->get();
    expect($tasks)->toHaveCount(2);
    foreach ($tasks as $task) {
        expect($task->project_id)->toBe($project->id);
        // Goal attaches via subjects (not a direct FK on tasks).
        $subjectIds = $task->subjects()->pluck('id')->all();
        expect($subjectIds)->toContain($goal->id);
    }
});

it('createBulkGoal + createBulkProject spawn and pre-select', function () {
    authedInHousehold();

    Livewire::test('tasks-index')
        ->call('createBulkGoal', 'Get fit')
        ->assertSet('bulkGoalId', function ($v) {
            return $v !== null && Goal::where('title', 'Get fit')->value('id') === $v;
        })
        ->call('createBulkProject', 'Home gym')
        ->assertSet('bulkProjectId', function ($v) {
            return $v !== null && Project::where('name', 'Home gym')->value('id') === $v;
        });
});

it('does nothing when the textarea is empty', function () {
    authedInHousehold();

    Livewire::test('tasks-index')
        ->set('bulkOpen', true)
        ->set('bulkInput', "   \n\n   ")
        ->call('bulkSave');

    expect(Task::count())->toBe(0);
});

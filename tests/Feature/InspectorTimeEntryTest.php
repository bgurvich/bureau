<?php

use App\Models\Project;
use App\Models\TimeEntry;
use Livewire\Livewire;

it('creates a manual backlog time entry from the inspector', function () {
    $user = authedInHousehold();
    $project = Project::create(['user_id' => $user->id, 'name' => 'Client work', 'slug' => 'client-work']);

    Livewire::test('inspector.time-entry-form')
        ->set('activity_date', '2026-04-15')
        ->set('hours', '2.5')
        ->set('project_id', $project->id)
        ->set('description', 'Wrote the spec')
        ->set('billable', true)
        ->call('save')
        ->assertHasNoErrors();

    $entry = TimeEntry::firstOrFail();
    expect($entry->user_id)->toBe($user->id)
        ->and($entry->project_id)->toBe($project->id)
        ->and((int) $entry->duration_seconds)->toBe(9000)
        ->and($entry->activity_date->toDateString())->toBe('2026-04-15')
        ->and((bool) $entry->billable)->toBeTrue()
        ->and($entry->description)->toBe('Wrote the spec')
        ->and($entry->started_at)->not->toBeNull()
        ->and($entry->ended_at)->not->toBeNull()
        ->and($entry->ended_at->gt($entry->started_at))->toBeTrue();
});

it('rejects a time entry with missing date or non-positive hours', function () {
    authedInHousehold();

    Livewire::test('inspector.time-entry-form')
        ->set('activity_date', '')
        ->set('hours', '0')
        ->call('save')
        ->assertHasErrors(['activity_date', 'hours']);
});

it('edits an existing time entry', function () {
    $user = authedInHousehold();
    $entry = TimeEntry::create([
        'user_id' => $user->id,
        'activity_date' => '2026-04-10',
        'started_at' => '2026-04-10 09:00:00',
        'ended_at' => '2026-04-10 10:00:00',
        'duration_seconds' => 3600,
        'description' => 'Orig',
        'billable' => false,
    ]);

    Livewire::test('inspector.time-entry-form', ['id' => $entry->id])
        ->assertSet('hours', '1')
        ->set('hours', '1.5')
        ->set('description', 'Updated')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $entry->fresh();
    expect((int) $fresh->duration_seconds)->toBe(5400)
        ->and($fresh->description)->toBe('Updated');
});

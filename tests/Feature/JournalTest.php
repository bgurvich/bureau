<?php

use App\Models\JournalEntry;
use App\Models\Vehicle;
use Livewire\Livewire;

it('creates a journal entry via the inspector form', function () {
    authedInHousehold();

    Livewire::test('inspector.journal-entry-form')
        ->set('occurred_on', '2026-04-22')
        ->set('title', 'Quiet Wednesday')
        ->set('body', 'Wrapped up inspector refactor. Walked the dogs after dinner.')
        ->set('mood', 'good')
        ->set('weather', '68°F, partly cloudy')
        ->set('location', 'Home')
        ->call('save');

    $entry = JournalEntry::firstOrFail();
    expect($entry->occurred_on->toDateString())->toBe('2026-04-22')
        ->and($entry->title)->toBe('Quiet Wednesday')
        ->and($entry->body)->toContain('Wrapped up inspector')
        ->and($entry->mood)->toBe('good')
        ->and($entry->weather)->toBe('68°F, partly cloudy')
        ->and($entry->location)->toBe('Home')
        ->and($entry->private)->toBeTrue()
        ->and($entry->user_id)->not->toBeNull();
});

it('requires body on save', function () {
    authedInHousehold();

    Livewire::test('inspector.journal-entry-form')
        ->set('occurred_on', '2026-04-22')
        ->set('body', '')
        ->call('save')
        ->assertHasErrors(['body']);

    expect(JournalEntry::count())->toBe(0);
});

it('edits an existing journal entry', function () {
    authedInHousehold();
    $entry = JournalEntry::create([
        'occurred_on' => '2026-04-10',
        'body' => 'Old body',
        'mood' => 'neutral',
    ]);

    Livewire::test('inspector.journal-entry-form', ['id' => $entry->id])
        ->assertSet('body', 'Old body')
        ->assertSet('mood', 'neutral')
        ->set('body', 'Updated body')
        ->set('mood', 'great')
        ->call('save');

    $entry->refresh();
    expect($entry->body)->toBe('Updated body')
        ->and($entry->mood)->toBe('great');
});

it('links a subject and the subject can look the entry up in reverse', function () {
    authedInHousehold();
    $vehicle = Vehicle::create(['kind' => 'car', 'model' => 'Civic']);

    Livewire::test('inspector.journal-entry-form')
        ->set('occurred_on', '2026-04-20')
        ->set('body', 'Took Civic in for new tires. Felt rewarding to finally cross that off.')
        ->set('subject_refs', ['vehicle:'.$vehicle->id])
        ->call('save');

    $entry = JournalEntry::firstOrFail();
    expect($entry->subjects()->pluck('id')->all())->toContain($vehicle->id);
});

it('Journal index groups entries by month and filters by year / mood', function () {
    authedInHousehold();

    JournalEntry::create(['occurred_on' => '2026-04-22', 'body' => 'April 22 entry', 'mood' => 'good']);
    JournalEntry::create(['occurred_on' => '2026-04-10', 'body' => 'April 10 entry', 'mood' => 'low']);
    JournalEntry::create(['occurred_on' => '2026-03-05', 'body' => 'March 5 entry', 'mood' => 'good']);
    JournalEntry::create(['occurred_on' => '2025-12-31', 'body' => 'NYE entry', 'mood' => 'reflective']);

    $c = Livewire::test('journal-index');
    $c->assertSee('April 22 entry')
        ->assertSee('March 5 entry')
        ->assertSee('NYE entry');

    // Year filter: 2025 only
    $c->set('yearFilter', 2025)
        ->assertSee('NYE entry')
        ->assertDontSee('April 22 entry');

    // Mood filter without year: good entries only
    $c->set('yearFilter', null)
        ->set('moodFilter', 'good')
        ->assertSee('April 22 entry')
        ->assertSee('March 5 entry')
        ->assertDontSee('April 10 entry')
        ->assertDontSee('NYE entry');
});

it('Journal index lists distinct years with current year pinned even when empty', function () {
    authedInHousehold();
    JournalEntry::create(['occurred_on' => '2024-06-01', 'body' => 'old entry']);

    $c = Livewire::test('journal-index');
    $years = $c->get('yearOptions');

    $currentYear = (int) now()->format('Y');
    expect($years)->toContain(2024)
        ->and($years)->toContain($currentYear);
});

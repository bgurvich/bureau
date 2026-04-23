<?php

use App\Models\MediaLogEntry;
use Livewire\Livewire;

it('creates a media log entry via the inspector form', function () {
    authedInHousehold();

    Livewire::test('inspector.media-log-entry-form')
        ->set('kind', 'book')
        ->set('title', 'Piranesi')
        ->set('creator', 'Susanna Clarke')
        ->set('status', 'in_progress')
        ->call('save');

    $e = MediaLogEntry::firstOrFail();
    expect($e->kind)->toBe('book')
        ->and($e->title)->toBe('Piranesi')
        ->and($e->creator)->toBe('Susanna Clarke')
        ->and($e->status)->toBe('in_progress')
        // updatedStatus hook stamps started_on when flipping to in_progress.
        ->and($e->started_on?->toDateString())->toBe(now()->toDateString())
        ->and($e->user_id)->not->toBeNull();
});

it('stamps finished_on when the user flips status to done', function () {
    authedInHousehold();
    $e = MediaLogEntry::create([
        'kind' => 'film',
        'title' => 'Arrival',
        'status' => 'in_progress',
        'started_on' => '2026-03-01',
    ]);

    Livewire::test('inspector.media-log-entry-form', ['id' => $e->id])
        ->set('status', 'done')
        ->assertSet('finished_on', now()->toDateString())
        ->call('save');

    expect($e->fresh()->finished_on?->toDateString())->toBe(now()->toDateString());
});

it('preserves a manually-entered finished_on when set before flipping status', function () {
    authedInHousehold();

    Livewire::test('inspector.media-log-entry-form')
        ->set('kind', 'book')
        ->set('title', 'Finished months ago')
        ->set('finished_on', '2026-01-15')
        ->set('status', 'done')
        ->call('save')
        ->assertHasNoErrors();

    $e = MediaLogEntry::firstOrFail();
    expect($e->finished_on?->toDateString())->toBe('2026-01-15');
});

it('rejects finished_on that precedes started_on', function () {
    authedInHousehold();

    Livewire::test('inspector.media-log-entry-form')
        ->set('kind', 'book')
        ->set('title', 'Backwards')
        ->set('started_on', '2026-03-01')
        ->set('finished_on', '2026-02-01')
        ->set('status', 'done')
        ->call('save')
        ->assertHasErrors(['finished_on']);

    expect(MediaLogEntry::count())->toBe(0);
});

it('index lists entries by lifecycle priority and filters by kind + status', function () {
    authedInHousehold();

    MediaLogEntry::create(['kind' => 'book', 'title' => 'A', 'status' => 'wishlist']);
    MediaLogEntry::create(['kind' => 'book', 'title' => 'B', 'status' => 'in_progress']);
    MediaLogEntry::create(['kind' => 'book', 'title' => 'C', 'status' => 'done', 'finished_on' => '2026-04-01']);
    MediaLogEntry::create(['kind' => 'film', 'title' => 'D', 'status' => 'wishlist']);

    $c = Livewire::test('media-log-index');

    $titles = $c->get('entries')->pluck('title')->all();
    // in_progress first, then wishlist, then done.
    expect($titles)->toBe(['B', 'A', 'D', 'C']);

    expect($c->get('statusCounts')['wishlist'])->toBe(2)
        ->and($c->get('statusCounts')['in_progress'])->toBe(1)
        ->and($c->get('statusCounts')['done'])->toBe(1);

    $c->set('kindFilter', 'film');
    expect($c->get('entries')->count())->toBe(1)
        ->and($c->get('entries')->first()->title)->toBe('D');

    $c->set('kindFilter', '')->set('statusFilter', 'done');
    expect($c->get('entries')->count())->toBe(1)
        ->and($c->get('entries')->first()->title)->toBe('C');
});

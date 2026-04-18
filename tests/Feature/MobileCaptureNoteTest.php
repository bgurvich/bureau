<?php

use App\Models\Note;
use Livewire\Livewire;

it('renders the note capture page for an authed user', function () {
    authedInHousehold();

    $this->get(route('mobile.capture.note'))
        ->assertOk()
        ->assertSee(__('New note'))
        ->assertSee(__('Dictate'));
});

it('saves a typed note and loops on Save & next', function () {
    $user = authedInHousehold();

    Livewire::test('mobile.capture-note')
        ->set('body', 'Pick up keys from the front desk.')
        ->call('save', true)
        ->assertSet('savedCount', 1)
        ->assertSet('body', '');

    $note = Note::firstOrFail();
    expect($note->body)->toBe('Pick up keys from the front desk.')
        ->and($note->user_id)->toBe($user->id)
        ->and((bool) $note->pinned)->toBeFalse();
});

it('persists pinned + private + title when provided', function () {
    authedInHousehold();

    Livewire::test('mobile.capture-note')
        ->set('title', 'Locker combo')
        ->set('body', '14-22-7')
        ->set('pinned', true)
        ->set('private', true)
        ->call('save', false);

    $note = Note::firstOrFail();
    expect($note->title)->toBe('Locker combo')
        ->and((bool) $note->pinned)->toBeTrue()
        ->and((bool) $note->private)->toBeTrue();
});

it('rejects an empty body', function () {
    authedInHousehold();

    Livewire::test('mobile.capture-note')
        ->set('body', '   ')
        ->call('save', true)
        ->assertHasErrors(['body']);

    expect(Note::count())->toBe(0);
});

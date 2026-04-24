<?php

declare(strict_types=1);

use App\Models\Task;
use Livewire\Livewire;

it('renders the mobile bulk-tasks page', function () {
    authedInHousehold();

    $this->get(route('mobile.tasks.bulk'))
        ->assertOk()
        ->assertSeeText('Bulk tasks');
});

it('creates tasks from the textarea and clears it', function () {
    authedInHousehold();

    Livewire::test('mobile.tasks-bulk')
        ->set('text', "Pick up dry cleaning #errands\nCall mom P1")
        ->call('save')
        ->assertSet('text', '')
        ->assertSet('notes', ['Added 2 task(s).']);

    expect(Task::count())->toBe(2);
    expect(Task::where('title', 'Call mom')->value('priority'))->toBe(1);
});

it('falls back to a note when save is pressed with empty text', function () {
    authedInHousehold();

    Livewire::test('mobile.tasks-bulk')
        ->set('text', '   ')
        ->call('save');

    expect(Task::count())->toBe(0);
});

it('capture page links to the bulk-tasks route', function () {
    authedInHousehold();

    $this->get(route('mobile.capture'))
        ->assertOk()
        ->assertSee(route('mobile.tasks.bulk'), escape: false);
});

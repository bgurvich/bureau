<?php

declare(strict_types=1);

use App\Models\Task;
use Livewire\Livewire;

it('opens on tasks-bulk-open event and closes via close()', function () {
    authedInHousehold();

    Livewire::test('tasks-bulk-modal')
        ->assertSet('open', false)
        ->call('show')
        ->assertSet('open', true)
        ->call('close')
        ->assertSet('open', false)
        ->assertSet('text', '');
});

it('creates tasks and clears the textarea', function () {
    authedInHousehold();

    Livewire::test('tasks-bulk-modal')
        ->call('show')
        ->set('text', "Fix the oven\nDo laundry P1")
        ->call('save')
        ->assertSet('text', '');

    expect(Task::count())->toBe(2);
});

it('reports the empty-textarea case with a note', function () {
    authedInHousehold();

    Livewire::test('tasks-bulk-modal')
        ->call('show')
        ->set('text', '   ')
        ->call('save');

    expect(Task::count())->toBe(0);
});

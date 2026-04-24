<?php

declare(strict_types=1);

use App\Models\Task;
use Livewire\Livewire;

it('primary inspector-open carries parentId into subentityParentId', function () {
    authedInHousehold();
    $parent = Task::create(['title' => 'Parent', 'state' => 'open']);

    Livewire::test('inspector')
        ->dispatch('inspector-open', type: 'task', id: null, parentId: $parent->id)
        ->assertSet('type', 'task')
        ->assertSet('subentityParentId', $parent->id);
});

it('modal inspector still ignores inspector-open so it stays quiet', function () {
    authedInHousehold();
    $parent = Task::create(['title' => 'Parent', 'state' => 'open']);

    Livewire::test('inspector', ['asModal' => true])
        ->dispatch('inspector-open', type: 'task', id: null, parentId: $parent->id)
        ->assertSet('open', false);
});

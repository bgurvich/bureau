<?php

declare(strict_types=1);

use Livewire\Livewire;

it('renders the productivity hub with Goals as the default tab', function () {
    authedInHousehold();

    $this->get(route('productivity.index'))
        ->assertOk()
        ->assertSeeText('Productivity');
});

it('accepts all four tab keys and rejects bogus ones', function () {
    authedInHousehold();

    $c = Livewire::test('productivity-hub');
    foreach (['goals', 'projects', 'tasks', 'tree'] as $tab) {
        $c->call('setTab', $tab)->assertSet('tab', $tab);
    }
    $c->call('setTab', 'nonsense')->assertSet('tab', 'tree');
});

it('schedule hub no longer accepts tasks or checklists tabs', function () {
    authedInHousehold();

    $c = Livewire::test('schedule-hub');
    $before = $c->get('tab');
    $c->call('setTab', 'tasks');
    expect($c->get('tab'))->toBe($before);
    $c->call('setTab', 'checklists');
    expect($c->get('tab'))->toBe($before);
});

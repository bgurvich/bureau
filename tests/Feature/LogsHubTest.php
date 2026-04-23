<?php

use Livewire\Livewire;

it('logs hub renders with the Journal tab by default', function () {
    authedInHousehold();

    Livewire::test('logs-hub')
        ->assertSet('tab', 'journal')
        ->assertSee('Logs')
        ->assertSee('Journal')
        ->assertSee('Decisions')
        ->assertSee('Reading / watching')
        ->assertSee('Food');
});

it('logs hub remembers the last-selected tab', function () {
    authedInHousehold();

    Livewire::test('logs-hub')
        ->call('setTab', 'decisions')
        ->assertSet('tab', 'decisions');

    // Fresh mount reads the remembered tab
    Livewire::test('logs-hub')
        ->assertSet('tab', 'decisions');
});

it('logs hub rejects unknown tab values', function () {
    authedInHousehold();

    Livewire::test('logs-hub')
        ->call('setTab', 'not-a-real-tab')
        ->assertSet('tab', 'journal');
});

it('logs hub renders against /logs route for an authed user', function () {
    $user = authedInHousehold();
    $this->actingAs($user);

    $this->get(route('life.logs'))->assertOk();
});

<?php

use Livewire\Livewire;

it('logs hub renders with the Stream tab by default', function () {
    authedInHousehold();

    Livewire::test('logs-hub')
        ->assertSet('tab', 'stream')
        ->assertSee('Logs')
        ->assertSee('Stream')
        ->assertSee('Journal')
        ->assertSee('Decisions')
        ->assertSee('Reading / watching')
        ->assertSee('Food')
        ->assertSee('Body')
        ->assertSee('Time');
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
        ->assertSet('tab', 'stream');
});

it('logs hub renders against /logs route for an authed user', function () {
    $user = authedInHousehold();
    $this->actingAs($user);

    $this->get(route('life.logs'))->assertOk();
});

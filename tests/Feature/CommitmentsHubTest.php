<?php

use Livewire\Livewire;

it('commitments hub renders with Contracts as the default tab', function () {
    authedInHousehold();

    Livewire::test('commitments-hub')
        ->assertSet('tab', 'contracts')
        ->assertSee('Commitments')
        ->assertSee('Contracts')
        ->assertSee('Insurance');
});

it('commitments hub remembers the last-selected tab', function () {
    authedInHousehold();

    Livewire::test('commitments-hub')
        ->call('setTab', 'insurance')
        ->assertSet('tab', 'insurance');

    Livewire::test('commitments-hub')
        ->assertSet('tab', 'insurance');
});

it('/money/commitments route renders for an authed user', function () {
    $user = authedInHousehold();
    $this->actingAs($user);
    $this->get(route('fiscal.commitments'))->assertOk();
});

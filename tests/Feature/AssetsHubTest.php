<?php

declare(strict_types=1);

use Livewire\Livewire;

it('renders the assets hub with the Properties tab by default', function () {
    authedInHousehold();

    Livewire::test('assets-hub')
        ->assertSet('tab', 'properties')
        ->assertSee(__('Properties'))
        ->assertSee(__('Vehicles'))
        ->assertSee(__('Inventory'));
});

it('switches between asset tabs', function () {
    authedInHousehold();

    Livewire::test('assets-hub')
        ->call('setTab', 'vehicles')
        ->assertSet('tab', 'vehicles')
        ->call('setTab', 'inventory')
        ->assertSet('tab', 'inventory')
        ->call('setTab', 'properties')
        ->assertSet('tab', 'properties');
});

it('refuses unknown tab values', function () {
    authedInHousehold();

    Livewire::test('assets-hub')
        ->call('setTab', 'bogus')
        ->assertSet('tab', 'properties');
});

it('answers at /assets with 200 and keeps the deep-link routes alive', function () {
    authedInHousehold();

    $this->get(route('assets.index'))->assertOk();
    $this->get(route('assets.properties'))->assertOk();
    $this->get(route('assets.vehicles'))->assertOk();
    $this->get(route('assets.inventory'))->assertOk();
});

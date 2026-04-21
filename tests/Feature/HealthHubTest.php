<?php

declare(strict_types=1);

use Livewire\Livewire;

it('renders the health hub with the Appointments tab by default', function () {
    authedInHousehold();

    Livewire::test('health-hub')
        ->assertSet('tab', 'appointments')
        ->assertSee(__('Appointments'))
        ->assertSee(__('Prescriptions'))
        ->assertSee(__('Providers'));
});

it('switches between health tabs', function () {
    authedInHousehold();

    Livewire::test('health-hub')
        ->call('setTab', 'prescriptions')
        ->assertSet('tab', 'prescriptions')
        ->call('setTab', 'providers')
        ->assertSet('tab', 'providers')
        ->call('setTab', 'appointments')
        ->assertSet('tab', 'appointments');
});

it('refuses unknown tab values', function () {
    authedInHousehold();

    Livewire::test('health-hub')
        ->call('setTab', 'bogus')
        ->assertSet('tab', 'appointments');
});

it('answers at /health with 200 and keeps the deep-link routes alive', function () {
    authedInHousehold();

    $this->get(route('health.index'))->assertOk();
    $this->get(route('health.providers'))->assertOk();
    $this->get(route('health.prescriptions'))->assertOk();
    $this->get(route('health.appointments'))->assertOk();
});

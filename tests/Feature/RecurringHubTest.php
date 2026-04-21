<?php

declare(strict_types=1);

use Livewire\Livewire;

it('renders the recurring hub with the Bills tab by default', function () {
    authedInHousehold();

    Livewire::test('recurring-hub')
        ->assertSet('tab', 'bills')
        ->assertSee(__('Bills & Income'))
        ->assertSee(__('Subscriptions'));
});

it('switches between bills and subscriptions tabs', function () {
    authedInHousehold();

    Livewire::test('recurring-hub')
        ->call('setTab', 'subscriptions')
        ->assertSet('tab', 'subscriptions')
        ->call('setTab', 'bills')
        ->assertSet('tab', 'bills');
});

it('refuses unknown tab values', function () {
    authedInHousehold();

    Livewire::test('recurring-hub')
        ->call('setTab', 'bogus')
        ->assertSet('tab', 'bills');
});

it('routes fiscal.recurring to the hub and keeps fiscal.bills alive', function () {
    authedInHousehold();

    $this->get(route('fiscal.recurring'))->assertOk();
    $this->get(route('fiscal.bills'))->assertOk();
    $this->get(route('fiscal.subscriptions'))->assertOk();
});

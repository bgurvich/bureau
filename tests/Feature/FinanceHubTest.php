<?php

declare(strict_types=1);

use Livewire\Livewire;

it('renders the finance hub with the Overview tab by default', function () {
    authedInHousehold();

    Livewire::test('finance-hub')
        ->assertSet('tab', 'summary')
        ->assertSee(__('Overview'))
        ->assertSee(__('Year over year'));
});

it('switches between summary and yoy tabs', function () {
    authedInHousehold();

    Livewire::test('finance-hub')
        ->call('setTab', 'yoy')
        ->assertSet('tab', 'yoy')
        ->call('setTab', 'summary')
        ->assertSet('tab', 'summary');
});

it('refuses unknown tab values', function () {
    authedInHousehold();

    Livewire::test('finance-hub')
        ->call('setTab', 'bogus')
        ->assertSet('tab', 'summary');
});

it('answers at /finance with 200 and keeps the deep-link /finance/yoy alive', function () {
    authedInHousehold();

    $this->get(route('fiscal.overview'))->assertOk();
    $this->get(route('fiscal.yoy'))->assertOk();
});

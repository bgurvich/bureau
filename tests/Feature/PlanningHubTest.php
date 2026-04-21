<?php

declare(strict_types=1);

use Livewire\Livewire;

it('renders the planning hub with the Budgets tab by default', function () {
    authedInHousehold();

    Livewire::test('planning-hub')
        ->assertSet('tab', 'budgets')
        ->assertSee(__('Budgets'))
        ->assertSee(__('Savings goals'));
});

it('switches between budgets and savings_goals tabs', function () {
    authedInHousehold();

    Livewire::test('planning-hub')
        ->call('setTab', 'savings_goals')
        ->assertSet('tab', 'savings_goals')
        ->call('setTab', 'budgets')
        ->assertSet('tab', 'budgets');
});

it('refuses unknown tab values', function () {
    authedInHousehold();

    Livewire::test('planning-hub')
        ->call('setTab', 'bogus')
        ->assertSet('tab', 'budgets');
});

it('answers at /planning with 200 and keeps the deep-link routes alive', function () {
    authedInHousehold();

    $this->get(route('fiscal.planning'))->assertOk();
    $this->get(route('fiscal.budgets'))->assertOk();
    $this->get(route('fiscal.savings_goals'))->assertOk();
});

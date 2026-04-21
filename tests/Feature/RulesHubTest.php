<?php

declare(strict_types=1);

use Livewire\Livewire;

it('renders the rules hub with the Category rules tab by default', function () {
    authedInHousehold();

    Livewire::test('rules-hub')
        ->assertSet('tab', 'category')
        ->assertSee(__('Category rules'))
        ->assertSee(__('Tag rules'));
});

it('switches between category and tag tabs', function () {
    authedInHousehold();

    Livewire::test('rules-hub')
        ->call('setTab', 'tag')
        ->assertSet('tab', 'tag')
        ->call('setTab', 'category')
        ->assertSet('tab', 'category');
});

it('refuses unknown tab values', function () {
    authedInHousehold();

    Livewire::test('rules-hub')
        ->call('setTab', 'bogus')
        ->assertSet('tab', 'category');
});

it('answers at /rules with 200 and keeps the deep-link routes alive', function () {
    authedInHousehold();

    $this->get(route('fiscal.rules'))->assertOk();
    $this->get(route('fiscal.category_rules'))->assertOk();
    $this->get(route('fiscal.tag_rules'))->assertOk();
});

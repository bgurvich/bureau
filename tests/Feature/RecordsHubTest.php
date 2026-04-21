<?php

declare(strict_types=1);

use Livewire\Livewire;

it('renders the records hub with the Documents tab by default', function () {
    authedInHousehold();

    Livewire::test('records-hub')
        ->assertSet('tab', 'documents')
        ->assertSee(__('Documents'))
        ->assertSee(__('Media'))
        ->assertSee(__('Mail'))
        ->assertSee(__('Notes'))
        ->assertSee(__('Tags'));
});

it('switches between records tabs', function () {
    authedInHousehold();

    Livewire::test('records-hub')
        ->call('setTab', 'media')
        ->assertSet('tab', 'media')
        ->call('setTab', 'mail')
        ->assertSet('tab', 'mail')
        ->call('setTab', 'notes')
        ->assertSet('tab', 'notes')
        ->call('setTab', 'tags')
        ->assertSet('tab', 'tags')
        ->call('setTab', 'documents')
        ->assertSet('tab', 'documents');
});

it('refuses unknown tab values', function () {
    authedInHousehold();

    Livewire::test('records-hub')
        ->call('setTab', 'bogus')
        ->assertSet('tab', 'documents');
});

it('answers at /records with 200 and keeps the deep-link routes alive', function () {
    authedInHousehold();

    $this->get(route('records.index'))->assertOk();
    $this->get(route('records.documents'))->assertOk();
    $this->get(route('records.media'))->assertOk();
    $this->get(route('records.mail'))->assertOk();
    $this->get(route('records.notes'))->assertOk();
    $this->get(route('tags.index'))->assertOk();
});

<?php

declare(strict_types=1);

use Livewire\Livewire;

it('renders the ledger hub with the Accounts tab by default', function () {
    authedInHousehold();

    Livewire::test('ledger-hub')
        ->assertSet('tab', 'accounts')
        ->assertSee(__('Accounts'))
        ->assertSee(__('Transactions'))
        ->assertSee(__('Reconcile'))
        ->assertSee(__('Inbox'))
        ->assertSee(__('Import statements'))
        ->assertSee(__('Bookkeeper'));
});

it('switches the active tab via setTab', function () {
    authedInHousehold();

    Livewire::test('ledger-hub')
        ->call('setTab', 'transactions')
        ->assertSet('tab', 'transactions')
        ->call('setTab', 'reconcile')
        ->assertSet('tab', 'reconcile')
        ->call('setTab', 'inbox')
        ->assertSet('tab', 'inbox')
        ->call('setTab', 'import')
        ->assertSet('tab', 'import')
        ->call('setTab', 'bookkeeper')
        ->assertSet('tab', 'bookkeeper')
        ->call('setTab', 'accounts')
        ->assertSet('tab', 'accounts');
});

it('refuses to switch to an unknown tab', function () {
    authedInHousehold();

    Livewire::test('ledger-hub')
        ->call('setTab', 'malicious')
        ->assertSet('tab', 'accounts');
});

it('answers at /ledger with a 200', function () {
    authedInHousehold();

    $this->get(route('fiscal.ledger'))->assertOk();
});

it('still serves the old /accounts route directly', function () {
    authedInHousehold();

    // Regression guard — the hub adds a new URL, it does NOT remove the
    // deep-link surfaces. Old bookmarks and route('fiscal.accounts') uses
    // across the codebase must keep resolving.
    $this->get(route('fiscal.accounts'))->assertOk();
    $this->get(route('fiscal.transactions'))->assertOk();
    $this->get(route('fiscal.import.statements'))->assertOk();
});

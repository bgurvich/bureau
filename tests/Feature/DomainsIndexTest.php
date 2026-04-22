<?php

use App\Models\Contact;
use App\Models\Domain;
use Livewire\Livewire;

it('renders the domains index with totals + per-row expiry classes', function () {
    authedInHousehold();
    $reg = Contact::create(['kind' => 'org', 'display_name' => 'Namecheap']);

    Domain::create([
        'name' => 'example.com',
        'registrar' => 'Namecheap',
        'registered_on' => '2023-05-01',
        'expires_on' => now()->addDays(15)->toDateString(),
        'auto_renew' => false,
        'annual_cost' => 12.99,
        'registrant_contact_id' => $reg->id,
    ]);
    Domain::create([
        'name' => 'old.net',
        'expires_on' => now()->subDays(10)->toDateString(),
        'auto_renew' => false,
        'annual_cost' => 20.00,
    ]);

    Livewire::test('domains-index')
        ->assertSee('example.com')
        ->assertSee('old.net')
        ->assertSee('Namecheap')
        ->assertSee('32.99'); // 12.99 + 20.00 annual total
});

it('filters to expiring and expired via the ?status query param', function () {
    authedInHousehold();

    Domain::create(['name' => 'good.com', 'expires_on' => now()->addYear()->toDateString()]);
    Domain::create(['name' => 'soon.com', 'expires_on' => now()->addDays(10)->toDateString()]);
    Domain::create(['name' => 'dead.com', 'expires_on' => now()->subDays(5)->toDateString()]);

    $c = Livewire::test('domains-index');
    $c->assertSee('good.com')->assertSee('soon.com')->assertSee('dead.com');

    $c->set('statusFilter', 'expiring')
        ->assertDontSee('good.com')
        ->assertSee('soon.com')
        ->assertDontSee('dead.com');

    $c->set('statusFilter', 'expired')
        ->assertDontSee('good.com')
        ->assertDontSee('soon.com')
        ->assertSee('dead.com');
});

it('attention-radar counts only non-auto-renewing domains expiring within 30d', function () {
    authedInHousehold();

    // Counts
    Domain::create(['name' => 'counts.com', 'expires_on' => now()->addDays(15)->toDateString(), 'auto_renew' => false]);

    // Doesn't count — auto-renews
    Domain::create(['name' => 'auto.com', 'expires_on' => now()->addDays(15)->toDateString(), 'auto_renew' => true]);

    // Doesn't count — outside window
    Domain::create(['name' => 'far.com', 'expires_on' => now()->addDays(90)->toDateString(), 'auto_renew' => false]);

    // Doesn't count — already past due
    Domain::create(['name' => 'dead.com', 'expires_on' => now()->subDay()->toDateString(), 'auto_renew' => false]);

    $c = Livewire::test('attention-radar');
    expect($c->get('domainsExpiringSoon'))->toBe(1);
});

it('Assets hub renders the Domains tab panel', function () {
    authedInHousehold();
    Domain::create(['name' => 'assetstab.com']);

    Livewire::test('assets-hub')
        ->call('setTab', 'domains')
        ->assertSet('tab', 'domains')
        ->assertSeeLivewire('domains-index');
});

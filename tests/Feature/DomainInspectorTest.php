<?php

use App\Models\Domain;
use Livewire\Livewire;

it('creates a domain via the inspector form', function () {
    authedInHousehold();

    Livewire::test('inspector.domain-form')
        ->set('domain_name', 'Example.com')
        ->set('domain_registrar', 'Namecheap')
        ->set('domain_registered_on', '2024-01-15')
        ->set('domain_expires_on', '2026-01-15')
        ->set('domain_auto_renew', true)
        ->set('domain_annual_cost', '12.99')
        ->set('domain_nameservers', "ns1.example.com\nns2.example.com")
        ->call('save')
        ->assertHasNoErrors();

    $d = Domain::firstWhere('name', 'example.com');
    expect($d)->not->toBeNull()
        ->and($d->registrar)->toBe('Namecheap')
        ->and((bool) $d->auto_renew)->toBeTrue()
        ->and((float) $d->annual_cost)->toBe(12.99)
        ->and($d->expires_on->toDateString())->toBe('2026-01-15')
        ->and($d->nameservers)->toBe("ns1.example.com\nns2.example.com");
});

it('normalizes domain name to lowercase on save', function () {
    authedInHousehold();

    Livewire::test('inspector.domain-form')
        ->set('domain_name', 'MyDomain.COM')
        ->call('save')
        ->assertHasNoErrors();

    expect(Domain::first()->name)->toBe('mydomain.com');
});

it('rejects an expires_on date that precedes registered_on', function () {
    authedInHousehold();

    Livewire::test('inspector.domain-form')
        ->set('domain_name', 'bad.com')
        ->set('domain_registered_on', '2026-01-15')
        ->set('domain_expires_on', '2025-01-15')
        ->call('save')
        ->assertHasErrors(['domain_expires_on']);
});

it('edits an existing domain', function () {
    authedInHousehold();
    $d = Domain::create([
        'name' => 'old.com',
        'registrar' => 'OldRegistrar',
        'auto_renew' => false,
        'currency' => 'USD',
    ]);

    Livewire::test('inspector.domain-form', ['id' => $d->id])
        ->assertSet('domain_name', 'old.com')
        ->set('domain_registrar', 'NewRegistrar')
        ->set('domain_auto_renew', true)
        ->call('save');

    $fresh = $d->fresh();
    expect($fresh->registrar)->toBe('NewRegistrar')
        ->and((bool) $fresh->auto_renew)->toBeTrue();
});

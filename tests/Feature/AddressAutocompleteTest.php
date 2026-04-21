<?php

use App\Models\Contact;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
});

function nominatimRow(): array
{
    return [
        'display_name' => '123 Main St, Springfield, IL 62701, USA',
        'lat' => '39.7817',
        'lon' => '-89.6501',
        'address' => [
            'house_number' => '123',
            'road' => 'Main St',
            'city' => 'Springfield',
            'state' => 'Illinois',
            'postcode' => '62701',
            'country' => 'United States',
        ],
    ];
}

it('autocomplete returns an empty result for short queries', function () {
    authedInHousehold();
    Http::fake();

    $this->getJson(route('address.autocomplete', ['q' => 'ab']))
        ->assertOk()
        ->assertJson(['results' => []]);

    Http::assertNothingSent();
});

it('autocomplete proxies the query to Nominatim and normalizes results', function () {
    authedInHousehold();
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([nominatimRow()]),
    ]);

    $response = $this->getJson(route('address.autocomplete', ['q' => '123 Main']))
        ->assertOk();
    $first = $response->json('results.0');
    expect($first['formatted'])->toBe('123 Main St, Springfield, IL 62701, USA')
        ->and($first['street'])->toBe('123 Main St')
        ->and($first['city'])->toBe('Springfield')
        ->and($first['state'])->toBe('Illinois')
        ->and($first['postal_code'])->toBe('62701')
        ->and($first['country'])->toBe('United States');

    // Proper User-Agent per Nominatim policy
    Http::assertSent(function ($req) {
        return str_contains($req->url(), 'nominatim.openstreetmap.org/search')
            && str_contains($req->header('User-Agent')[0] ?? '', 'Bureau');
    });
});

it('autocomplete caches Nominatim responses per-query', function () {
    authedInHousehold();
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([nominatimRow()]),
    ]);

    $this->getJson(route('address.autocomplete', ['q' => '123 Main']));
    $this->getJson(route('address.autocomplete', ['q' => '123 Main']));
    $this->getJson(route('address.autocomplete', ['q' => '123 MAIN']));   // different case, same cache key

    Http::assertSentCount(1);
});

it('autocomplete returns an empty list on Nominatim error without throwing', function () {
    authedInHousehold();
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response('boom', 500),
    ]);

    $this->getJson(route('address.autocomplete', ['q' => '456 Oak']))
        ->assertOk()
        ->assertJson(['results' => []]);
});

it('Inspector saves a Contact with structured address populated from the picker', function () {
    authedInHousehold();

    Livewire::test('inspector')
        ->call('openInspector', 'contact')
        ->set('kind', 'person')
        ->set('display_name', 'Jane Doe')
        ->set('contact_address_line', '123 Main St')
        ->set('contact_address_city', 'Springfield')
        ->set('contact_address_region', 'Illinois')
        ->set('contact_address_postcode', '62701')
        ->set('contact_address_country', 'United States')
        ->call('save');

    $contact = Contact::firstOrFail();
    expect($contact->addresses)->toBeArray()
        ->and($contact->addresses[0]['line'])->toBe('123 Main St')
        ->and($contact->addresses[0]['city'])->toBe('Springfield')
        ->and($contact->addresses[0]['region'])->toBe('Illinois')
        ->and($contact->addresses[0]['postcode'])->toBe('62701')
        ->and($contact->addresses[0]['country'])->toBe('United States');
});

it('Inspector reloads a Contact address from JSON into the form fields', function () {
    authedInHousehold();
    $c = Contact::create([
        'kind' => 'person',
        'display_name' => 'Ada',
        'addresses' => [[
            'line' => '1 Analytical Engine Lane',
            'city' => 'London',
            'country' => 'UK',
        ]],
    ]);

    $component = Livewire::test('inspector')->call('openInspector', 'contact', $c->id);
    expect($component->get('contact_address_line'))->toBe('1 Analytical Engine Lane')
        ->and($component->get('contact_address_city'))->toBe('London')
        ->and($component->get('contact_address_country'))->toBe('UK');
});

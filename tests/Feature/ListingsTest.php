<?php

declare(strict_types=1);

use App\Models\InventoryItem;
use App\Models\Listing;
use Livewire\Livewire;

it('creates a listing via the inspector form', function () {
    authedInHousehold();
    $item = InventoryItem::create(['name' => 'Retro lamp']);

    Livewire::test('inspector.listing-form', ['parentId' => $item->id])
        ->assertSet('title', 'Retro lamp')
        ->set('platform', 'ebay')
        ->set('price', '125.00')
        ->set('external_url', 'https://www.ebay.com/itm/999')
        ->set('status', 'live')
        ->set('posted_on', '2026-04-23')
        ->set('expires_on', '2026-05-23')
        ->call('save');

    $l = Listing::firstOrFail();
    expect($l->title)->toBe('Retro lamp')
        ->and($l->platform)->toBe('ebay')
        ->and((float) $l->price)->toBe(125.00)
        ->and($l->status)->toBe('live')
        ->and($l->external_url)->toBe('https://www.ebay.com/itm/999')
        ->and($l->inventory_item_id)->toBe($item->id);
});

it('auto-stamps ended_on when status transitions to sold / expired / cancelled', function () {
    authedInHousehold();

    Livewire::test('inspector.listing-form')
        ->set('title', 'Old phone')
        ->set('platform', 'craigslist')
        ->set('status', 'sold')
        ->set('sold_for', '40')
        ->call('save');

    expect(Listing::firstOrFail()->ended_on?->toDateString())->toBe(now()->toDateString());
});

it('respects a user-supplied ended_on instead of auto-stamping', function () {
    authedInHousehold();

    Livewire::test('inspector.listing-form')
        ->set('title', 'Old phone')
        ->set('platform', 'craigslist')
        ->set('status', 'expired')
        ->set('ended_on', '2026-01-15')
        ->call('save');

    expect(Listing::firstOrFail()->ended_on?->toDateString())->toBe('2026-01-15');
});

it('rejects expires_on that precedes posted_on', function () {
    authedInHousehold();

    Livewire::test('inspector.listing-form')
        ->set('title', 'Nope')
        ->set('platform', 'ebay')
        ->set('status', 'live')
        ->set('posted_on', '2026-04-01')
        ->set('expires_on', '2026-03-01')
        ->call('save')
        ->assertHasErrors(['expires_on']);

    expect(Listing::count())->toBe(0);
});

it('index status chips filter the listings', function () {
    authedInHousehold();
    Listing::create(['title' => 'Live eBay', 'platform' => 'ebay', 'status' => 'live']);
    Listing::create(['title' => 'Sold thing', 'platform' => 'ebay', 'status' => 'sold']);
    Listing::create(['title' => 'Draft', 'platform' => 'craigslist', 'status' => 'draft']);

    $c = Livewire::test('listings-index');
    // Default filter is 'live'.
    expect($c->get('listings')->pluck('title')->all())->toBe(['Live eBay']);

    $c->set('statusFilter', 'sold');
    expect($c->get('listings')->pluck('title')->all())->toBe(['Sold thing']);

    $c->set('statusFilter', '');
    expect($c->get('listings')->count())->toBe(3);
});

it('Attention radar counts live listings expiring within 7 days', function () {
    authedInHousehold();

    Listing::create([
        'title' => 'Expiring soon', 'platform' => 'ebay', 'status' => 'live',
        'expires_on' => now()->addDays(3)->toDateString(),
    ]);
    Listing::create([
        'title' => 'Expiring later', 'platform' => 'ebay', 'status' => 'live',
        'expires_on' => now()->addDays(30)->toDateString(),
    ]);
    Listing::create([
        'title' => 'Already sold', 'platform' => 'ebay', 'status' => 'sold',
        'expires_on' => now()->addDays(3)->toDateString(),
    ]);

    $c = Livewire::test('attention-radar');
    expect($c->get('listingsExpiringSoon'))->toBe(1);
});

it('/listings route renders for an authed user', function () {
    $user = authedInHousehold();
    $this->actingAs($user);
    $this->get(route('assets.listings'))->assertOk();
});

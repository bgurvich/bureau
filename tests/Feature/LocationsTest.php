<?php

declare(strict_types=1);

use App\Models\InventoryItem;
use App\Models\Location;

it('walks ancestors from a deep descendant up to the root', function () {
    authedInHousehold();
    $house = Location::create(['name' => 'House', 'kind' => 'area']);
    $office = Location::create(['name' => 'Office', 'kind' => 'room', 'parent_id' => $house->id]);
    $desk = Location::create(['name' => 'Desk Drawer', 'kind' => 'container', 'parent_id' => $office->id]);

    $names = $desk->ancestors()->pluck('name')->all();
    expect($names)->toBe(['House', 'Office', 'Desk Drawer']);
});

it('breadcrumb joins ancestor names with the default separator', function () {
    authedInHousehold();
    $house = Location::create(['name' => 'House', 'kind' => 'area']);
    $office = Location::create(['name' => 'Office', 'kind' => 'room', 'parent_id' => $house->id]);

    expect($office->breadcrumb())->toBe('House › Office');
});

it('descendantIds returns self + all transitive children', function () {
    authedInHousehold();
    $house = Location::create(['name' => 'House', 'kind' => 'area']);
    $office = Location::create(['name' => 'Office', 'kind' => 'room', 'parent_id' => $house->id]);
    $garage = Location::create(['name' => 'Garage', 'kind' => 'room', 'parent_id' => $house->id]);
    $desk = Location::create(['name' => 'Desk', 'kind' => 'container', 'parent_id' => $office->id]);

    $ids = $house->descendantIds();
    sort($ids);
    $expected = [$house->id, $office->id, $garage->id, $desk->id];
    sort($expected);
    expect($ids)->toBe($expected);
});

it('ancestors is safe against a corrupted parent cycle', function () {
    authedInHousehold();
    $a = Location::create(['name' => 'A', 'kind' => 'other']);
    $b = Location::create(['name' => 'B', 'kind' => 'other', 'parent_id' => $a->id]);
    // Manually close the cycle bypassing app validation
    $a->update(['parent_id' => $b->id]);

    // Must not hang.
    $ancestors = $b->ancestors();
    expect($ancestors)->toHaveCount(2);
});

it('InventoryItem has a location relation', function () {
    authedInHousehold();
    $loc = Location::create(['name' => 'Office', 'kind' => 'room']);
    $item = InventoryItem::create(['name' => 'Monitor', 'location_id' => $loc->id]);

    expect($item->location->name)->toBe('Office');
});

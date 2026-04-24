<?php

declare(strict_types=1);

use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\Property;
use Livewire\Livewire;

it('createLocation spawns a root Location and selects it', function () {
    authedInHousehold();

    $c = Livewire::test('inspector.inventory-form')
        ->call('createLocation', 'Workshop');

    $loc = Location::where('name', 'Workshop')->firstOrFail();
    expect($c->get('inventory_location_id'))->toBe($loc->id);
    expect($loc->parent_id)->toBeNull();
    expect($loc->kind)->toBe('room');
});

it('createLocation inherits the currently selected property_id', function () {
    authedInHousehold();
    $property = Property::create(['kind' => 'home', 'name' => 'Home']);

    Livewire::test('inspector.inventory-form')
        ->set('inventory_property_id', $property->id)
        ->call('createLocation', 'Garage');

    $loc = Location::where('name', 'Garage')->firstOrFail();
    expect($loc->property_id)->toBe($property->id);
});

it('saves inventory_location_id through the form', function () {
    authedInHousehold();
    $loc = Location::create(['name' => 'Office', 'kind' => 'room']);

    Livewire::test('inspector.inventory-form')
        ->set('inventory_name', 'Monitor')
        ->set('inventory_location_id', $loc->id)
        ->call('save');

    $item = InventoryItem::where('name', 'Monitor')->firstOrFail();
    expect($item->location_id)->toBe($loc->id);
});

it('locationPickerOptions returns breadcrumb labels for nested locations', function () {
    authedInHousehold();
    $house = Location::create(['name' => 'House', 'kind' => 'area']);
    $office = Location::create(['name' => 'Office', 'kind' => 'room', 'parent_id' => $house->id]);
    $desk = Location::create(['name' => 'Desk', 'kind' => 'container', 'parent_id' => $office->id]);

    $opts = Livewire::test('inspector.inventory-form')->get('locationPickerOptions');

    expect($opts[$house->id])->toBe('House');
    expect($opts[$office->id])->toBe('House › Office');
    expect($opts[$desk->id])->toBe('House › Office › Desk');
});

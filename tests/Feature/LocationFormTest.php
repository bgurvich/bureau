<?php

declare(strict_types=1);

use App\Models\Location;
use Livewire\Livewire;

it('creates a root-level location', function () {
    authedInHousehold();

    Livewire::test('inspector.location-form')
        ->set('location_name', 'House')
        ->set('location_kind', 'area')
        ->call('save');

    $loc = Location::where('name', 'House')->firstOrFail();
    expect($loc->kind)->toBe('area');
    expect($loc->parent_id)->toBeNull();
});

it('pre-fills parent when mounted with parentId', function () {
    authedInHousehold();
    $house = Location::create(['name' => 'House', 'kind' => 'area']);

    Livewire::test('inspector.location-form', ['parentId' => $house->id])
        ->assertSet('location_parent_id', $house->id);
});

it('creates a nested child location', function () {
    authedInHousehold();
    $house = Location::create(['name' => 'House', 'kind' => 'area']);

    Livewire::test('inspector.location-form', ['parentId' => $house->id])
        ->set('location_name', 'Office')
        ->set('location_kind', 'room')
        ->call('save');

    $office = Location::where('name', 'Office')->firstOrFail();
    expect($office->parent_id)->toBe($house->id);
});

it('rejects self-parent on edit', function () {
    authedInHousehold();
    $loc = Location::create(['name' => 'Garage', 'kind' => 'room']);

    Livewire::test('inspector.location-form', ['id' => $loc->id])
        ->set('location_parent_id', $loc->id)
        ->call('save')
        ->assertHasErrors(['location_parent_id']);
});

it('rejects picking a descendant as the parent', function () {
    authedInHousehold();
    $house = Location::create(['name' => 'House', 'kind' => 'area']);
    $office = Location::create(['name' => 'Office', 'kind' => 'room', 'parent_id' => $house->id]);

    Livewire::test('inspector.location-form', ['id' => $house->id])
        ->set('location_name', 'House')
        ->set('location_kind', 'area')
        ->set('location_parent_id', $office->id)
        ->call('save')
        ->assertHasErrors(['location_parent_id']);
});

it('parent picker excludes self + descendants', function () {
    authedInHousehold();
    $house = Location::create(['name' => 'House', 'kind' => 'area']);
    $office = Location::create(['name' => 'Office', 'kind' => 'room', 'parent_id' => $house->id]);

    $opts = Livewire::test('inspector.location-form', ['id' => $house->id])->get('parentPickerOptions');
    expect(array_keys($opts))->not->toContain($house->id);
    expect(array_keys($opts))->not->toContain($office->id);
});

it('locations page renders via assets hub tab', function () {
    authedInHousehold();

    Livewire::test('locations-index')->assertOk();
});

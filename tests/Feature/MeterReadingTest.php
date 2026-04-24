<?php

use App\Models\MeterReading;
use App\Models\Property;
use Livewire\Livewire;

it('creates a meter reading via the inspector form', function () {
    authedInHousehold();
    $property = Property::create(['kind' => 'home', 'name' => 'Main house']);

    Livewire::test('inspector.meter-reading-form', ['parentId' => $property->id])
        ->set('kind', 'electric')
        ->set('read_on', '2026-04-22')
        ->set('value', '12345.6')
        ->set('unit', 'kWh')
        ->call('save');

    $m = MeterReading::firstOrFail();
    expect($m->property_id)->toBe($property->id)
        ->and($m->kind)->toBe('electric')
        ->and($m->read_on->toDateString())->toBe('2026-04-22')
        ->and((float) $m->value)->toBe(12345.6)
        ->and($m->unit)->toBe('kWh');
});

it('auto-fills the unit when kind changes and the unit is still the prior default', function () {
    authedInHousehold();
    $property = Property::create(['kind' => 'home', 'name' => 'Main house']);

    $c = Livewire::test('inspector.meter-reading-form', ['parentId' => $property->id]);
    expect($c->get('unit'))->toBe('kWh'); // electric default

    $c->set('kind', 'water');
    expect($c->get('unit'))->toBe('gal');

    $c->set('kind', 'gas');
    expect($c->get('unit'))->toBe('therm');
});

it('keeps a user-typed unit when kind changes', function () {
    authedInHousehold();
    $property = Property::create(['kind' => 'home', 'name' => 'Main house']);

    $c = Livewire::test('inspector.meter-reading-form', ['parentId' => $property->id])
        ->set('unit', 'imperial gallons')  // user's custom string
        ->set('kind', 'water');

    // "imperial gallons" isn't in the default map, so flipping kind
    // should preserve it rather than replacing with "gal".
    expect($c->get('unit'))->toBe('imperial gallons');
});

it('lists readings and computes the delta vs prior reading in the same series', function () {
    authedInHousehold();
    $home = Property::create(['kind' => 'home', 'name' => 'Main house']);
    $cabin = Property::create(['kind' => 'vacation', 'name' => 'Cabin']);

    // Two consecutive electric readings at the same property — delta
    // should surface.
    MeterReading::create(['property_id' => $home->id, 'kind' => 'electric', 'read_on' => '2026-02-01', 'value' => 10000, 'unit' => 'kWh']);
    MeterReading::create(['property_id' => $home->id, 'kind' => 'electric', 'read_on' => '2026-03-01', 'value' => 10520, 'unit' => 'kWh']);
    // Different property, same kind — isolated series, no delta between them.
    MeterReading::create(['property_id' => $cabin->id, 'kind' => 'electric', 'read_on' => '2026-03-05', 'value' => 200, 'unit' => 'kWh']);
    // Same property, different kind — separate series too.
    MeterReading::create(['property_id' => $home->id, 'kind' => 'water', 'read_on' => '2026-03-01', 'value' => 5000, 'unit' => 'gal']);

    $c = Livewire::test('meter-readings-index');
    $deltas = $c->get('deltas');

    $marchHome = MeterReading::where('property_id', $home->id)->where('kind', 'electric')->where('read_on', '2026-03-01')->firstOrFail();
    $febHome = MeterReading::where('property_id', $home->id)->where('kind', 'electric')->where('read_on', '2026-02-01')->firstOrFail();
    $cabinReading = MeterReading::where('property_id', $cabin->id)->firstOrFail();
    $waterReading = MeterReading::where('kind', 'water')->firstOrFail();

    // March electric on home: delta 520 kWh against Feb reading.
    expect($deltas[$marchHome->id]['delta'])->toBe(520.0)
        ->and($deltas[$marchHome->id]['prior_read_on'])->toBe('2026-02-01')
        // First reading in its series → null.
        ->and($deltas[$febHome->id])->toBeNull()
        // Cabin's lone electric reading → null.
        ->and($deltas[$cabinReading->id])->toBeNull()
        // Home's lone water reading → null (different kind = different series).
        ->and($deltas[$waterReading->id])->toBeNull();
});

it('filters by property and kind via ?property / ?kind query params', function () {
    authedInHousehold();
    $home = Property::create(['kind' => 'home', 'name' => 'Main house']);
    $cabin = Property::create(['kind' => 'vacation', 'name' => 'Cabin']);

    MeterReading::create(['property_id' => $home->id, 'kind' => 'electric', 'read_on' => '2026-03-01', 'value' => 100, 'unit' => 'kWh']);
    MeterReading::create(['property_id' => $home->id, 'kind' => 'water', 'read_on' => '2026-03-01', 'value' => 50, 'unit' => 'gal']);
    MeterReading::create(['property_id' => $cabin->id, 'kind' => 'electric', 'read_on' => '2026-03-01', 'value' => 200, 'unit' => 'kWh']);

    $c = Livewire::test('meter-readings-index');
    expect($c->get('readings')->count())->toBe(3);

    $c->set('propertyFilter', $home->id);
    expect($c->get('readings')->count())->toBe(2);

    $c->set('kindFilter', 'electric');
    expect($c->get('readings')->count())->toBe(1);
});

it('series summaries surface daily consumption rates + trend per (property, kind)', function () {
    authedInHousehold();
    $home = Property::create(['kind' => 'home', 'name' => 'Main']);
    // 3 readings 30 days apart, values 100 → 200 → 350.
    // Rate 1: (200-100)/30 ≈ 3.33 /day
    // Rate 2: (350-200)/30 = 5.00 /day  — latest, should flag trend=up.
    MeterReading::create(['property_id' => $home->id, 'kind' => 'electric', 'read_on' => '2026-01-01', 'value' => 100, 'unit' => 'kWh']);
    MeterReading::create(['property_id' => $home->id, 'kind' => 'electric', 'read_on' => '2026-01-31', 'value' => 200, 'unit' => 'kWh']);
    MeterReading::create(['property_id' => $home->id, 'kind' => 'electric', 'read_on' => '2026-03-02', 'value' => 350, 'unit' => 'kWh']);

    $c = Livewire::test('meter-readings-index');
    $summaries = $c->get('seriesSummaries');

    expect($summaries)->toHaveCount(1);
    expect($summaries[0]['kind'])->toBe('electric')
        ->and($summaries[0]['rates'])->toHaveCount(2)
        ->and($summaries[0]['latest_rate'])->toEqualWithDelta(5.0, 0.01)
        ->and($summaries[0]['trend'])->toBe('up');
});

it('series summaries skip series with just one reading', function () {
    authedInHousehold();
    $home = Property::create(['kind' => 'home', 'name' => 'Main']);
    MeterReading::create(['property_id' => $home->id, 'kind' => 'water', 'read_on' => '2026-01-01', 'value' => 500, 'unit' => 'gal']);

    expect(Livewire::test('meter-readings-index')->get('seriesSummaries'))->toBe([]);
});

it('sparklinePath returns null when there aren\'t enough points', function () {
    $c = Livewire::test('meter-readings-index');
    expect($c->instance()->sparklinePath([]))->toBeNull()
        ->and($c->instance()->sparklinePath([1.0]))->toBeNull()
        ->and($c->instance()->sparklinePath([1.0, 2.0]))->toStartWith('M');
});

it('Assets hub renders the Meter readings tab panel', function () {
    authedInHousehold();
    $property = Property::create(['kind' => 'home', 'name' => 'Main house']);
    MeterReading::create(['property_id' => $property->id, 'kind' => 'electric', 'read_on' => '2026-03-01', 'value' => 100, 'unit' => 'kWh']);

    Livewire::test('assets-hub')
        ->call('setTab', 'meters')
        ->assertSet('tab', 'meters')
        ->assertSeeLivewire('meter-readings-index');
});

<?php

use App\Models\BodyMeasurement;
use Livewire\Livewire;

it('creates a body measurement with all three metrics from lb input', function () {
    authedInHousehold();

    Livewire::test('inspector.body-measurement-form')
        ->set('measured_at', '2026-04-23T07:30')
        ->set('weight', '165.5')
        ->set('weight_unit', 'lb')
        ->set('body_fat_pct', '18.4')
        ->set('muscle_pct', '38.2')
        ->call('save');

    $m = BodyMeasurement::firstOrFail();
    // 165.5 lb ≈ 75.07 kg
    expect((float) $m->weight_kg)->toEqualWithDelta(75.07, 0.05)
        ->and((float) $m->body_fat_pct)->toBe(18.4)
        ->and((float) $m->muscle_pct)->toBe(38.2)
        ->and($m->user_id)->not->toBeNull();
});

it('creates a weight-only measurement (fat + muscle optional)', function () {
    authedInHousehold();

    Livewire::test('inspector.body-measurement-form')
        ->set('measured_at', '2026-04-23T07:30')
        ->set('weight', '165')
        ->set('weight_unit', 'lb')
        ->call('save');

    $m = BodyMeasurement::firstOrFail();
    expect((float) $m->weight_kg)->toBeGreaterThan(74.5)
        ->and($m->body_fat_pct)->toBeNull()
        ->and($m->muscle_pct)->toBeNull();
});

it('stores kg directly when unit = kg', function () {
    authedInHousehold();

    Livewire::test('inspector.body-measurement-form')
        ->set('measured_at', '2026-04-23T07:30')
        ->set('weight', '75')
        ->set('weight_unit', 'kg')
        ->call('save');

    expect((float) BodyMeasurement::firstOrFail()->weight_kg)->toBe(75.0);
});

it('rejects an empty measurement (no metric filled)', function () {
    authedInHousehold();

    Livewire::test('inspector.body-measurement-form')
        ->set('measured_at', '2026-04-23T07:30')
        ->call('save')
        ->assertHasErrors(['weight']);

    expect(BodyMeasurement::count())->toBe(0);
});

it('index shows latest value + delta vs prior reading', function () {
    authedInHousehold();
    // Two readings, 10 days apart. Delta should be +1 lb.
    BodyMeasurement::create([
        'measured_at' => now()->subDays(10),
        'weight_kg' => 74.84,  // 165.0 lb
    ]);
    BodyMeasurement::create([
        'measured_at' => now(),
        'weight_kg' => 75.30,  // 166.0 lb
    ]);

    $c = Livewire::test('body-measurements-index');
    $ld = $c->get('latestAndDelta');

    expect($ld['weight']['latest'])->toEqualWithDelta(166.0, 0.1)
        ->and($ld['weight']['delta'])->toEqualWithDelta(1.0, 0.1);
});

it('index window chip filter excludes older rows', function () {
    authedInHousehold();
    BodyMeasurement::create([
        'measured_at' => now()->subDays(120),
        'weight_kg' => 80,
    ]);
    BodyMeasurement::create([
        'measured_at' => now()->subDays(5),
        'weight_kg' => 75,
    ]);

    $c = Livewire::test('body-measurements-index')->set('window', '30');
    expect($c->get('measurements')->count())->toBe(1);

    $c->set('window', '365');
    expect($c->get('measurements')->count())->toBe(2);
});

it('index unit chip switches display between lb and kg', function () {
    authedInHousehold();
    BodyMeasurement::create([
        'measured_at' => now(),
        'weight_kg' => 75,
    ]);

    $c = Livewire::test('body-measurements-index')->set('unit', 'kg');
    expect($c->get('series')['weight'][0])->toBe(75.0);

    $c->set('unit', 'lb');
    expect($c->get('series')['weight'][0])->toEqualWithDelta(165.35, 0.1);
});

it('/body route renders for an authed user', function () {
    $user = authedInHousehold();
    $this->actingAs($user);
    $this->get(route('life.body'))->assertOk();
});

it('logs hub exposes Body as a tab and embeds the listing', function () {
    authedInHousehold();

    Livewire::test('logs-hub')
        ->call('setTab', 'body')
        ->assertSet('tab', 'body');
});

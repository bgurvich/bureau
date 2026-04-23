<?php

use App\Models\Contact;
use App\Models\Vehicle;
use App\Models\VehicleServiceLog;
use Livewire\Livewire;

it('creates a vehicle service log via the inspector form', function () {
    authedInHousehold();
    $vehicle = Vehicle::create(['kind' => 'car', 'make' => 'Honda', 'model' => 'Civic', 'year' => 2020, 'odometer' => 45000, 'odometer_unit' => 'mi']);
    $shop = Contact::create(['kind' => 'org', 'display_name' => 'Honda Service Center']);

    Livewire::test('inspector.vehicle-service-log-form', ['parentId' => $vehicle->id])
        ->set('service_date', '2026-04-22')
        ->set('kind', 'oil_change')
        ->set('odometer', '46200')
        ->set('cost', '79.95')
        ->set('provider_contact_id', $shop->id)
        ->call('save');

    $log = VehicleServiceLog::firstOrFail();
    expect($log->vehicle_id)->toBe($vehicle->id)
        ->and($log->kind)->toBe('oil_change')
        ->and($log->odometer)->toBe(46200)
        ->and($log->odometer_unit)->toBe('mi')
        ->and((float) $log->cost)->toBe(79.95)
        ->and($log->provider_contact_id)->toBe($shop->id);
});

it('pre-fills odometer + unit from the vehicle when opening for a new row', function () {
    authedInHousehold();
    $vehicle = Vehicle::create(['kind' => 'car', 'make' => 'Toyota', 'model' => 'Camry', 'odometer' => 80000, 'odometer_unit' => 'km']);

    Livewire::test('inspector.vehicle-service-log-form', ['parentId' => $vehicle->id])
        ->assertSet('odometer', '80000')
        ->assertSet('odometer_unit', 'km');
});

it('rolls the vehicle odometer forward when the service reading is higher', function () {
    authedInHousehold();
    $vehicle = Vehicle::create(['kind' => 'car', 'make' => 'Honda', 'model' => 'Civic', 'odometer' => 45000]);

    Livewire::test('inspector.vehicle-service-log-form', ['parentId' => $vehicle->id])
        ->set('service_date', '2026-04-22')
        ->set('kind', 'oil_change')
        ->set('odometer', '46200')
        ->call('save');

    expect($vehicle->fresh()->odometer)->toBe(46200);
});

it('leaves the vehicle odometer alone when the service reading is older / lower', function () {
    authedInHousehold();
    $vehicle = Vehicle::create(['kind' => 'car', 'make' => 'Honda', 'model' => 'Civic', 'odometer' => 50000]);

    // User is backfilling a historical service from a year ago when the
    // car had 44k miles on it. The current-odometer number (50k) must
    // not get overwritten.
    Livewire::test('inspector.vehicle-service-log-form', ['parentId' => $vehicle->id])
        ->set('service_date', '2025-04-22')
        ->set('kind', 'oil_change')
        ->set('odometer', '44000')
        ->call('save');

    expect($vehicle->fresh()->odometer)->toBe(50000);
});

it('saves next_due_on + next_due_odometer on the log', function () {
    authedInHousehold();
    $vehicle = Vehicle::create(['kind' => 'car', 'make' => 'Honda', 'model' => 'Civic', 'odometer' => 45000, 'odometer_unit' => 'mi']);

    Livewire::test('inspector.vehicle-service-log-form', ['parentId' => $vehicle->id])
        ->set('service_date', '2026-04-22')
        ->set('kind', 'oil_change')
        ->set('odometer', '46200')
        ->set('next_due_on', '2026-10-22')
        ->set('next_due_odometer', '51200')
        ->call('save');

    $log = VehicleServiceLog::firstOrFail();
    expect($log->next_due_on?->toDateString())->toBe('2026-10-22')
        ->and($log->next_due_odometer)->toBe(51200);
});

it('rejects a next_due_on that precedes service_date', function () {
    authedInHousehold();
    $vehicle = Vehicle::create(['kind' => 'car', 'make' => 'Honda', 'model' => 'Civic']);

    Livewire::test('inspector.vehicle-service-log-form', ['parentId' => $vehicle->id])
        ->set('service_date', '2026-04-22')
        ->set('kind', 'oil_change')
        ->set('next_due_on', '2026-01-01')
        ->call('save')
        ->assertHasErrors(['next_due_on']);
});

it('Attention radar counts services whose next_due_on is within 30 days', function () {
    authedInHousehold();
    $civic = Vehicle::create(['kind' => 'car', 'make' => 'Honda', 'model' => 'Civic']);

    // In-window: due 10 days from now.
    VehicleServiceLog::create([
        'vehicle_id' => $civic->id,
        'service_date' => now()->subMonths(6)->toDateString(),
        'kind' => 'oil_change',
        'next_due_on' => now()->addDays(10)->toDateString(),
    ]);
    // Out of window: due 60 days from now.
    VehicleServiceLog::create([
        'vehicle_id' => $civic->id,
        'service_date' => now()->subMonths(12)->toDateString(),
        'kind' => 'brakes',
        'next_due_on' => now()->addDays(60)->toDateString(),
    ]);

    $c = Livewire::test('attention-radar');
    expect($c->get('vehicleServicesDueSoon'))->toBe(1);
});

it('Attention radar dedupes by (vehicle, kind) — stale older logs don\'t double-count', function () {
    authedInHousehold();
    $civic = Vehicle::create(['kind' => 'car', 'make' => 'Honda', 'model' => 'Civic']);

    // Old log that WOULD be flagged if we didn't dedup (due date passed).
    VehicleServiceLog::create([
        'vehicle_id' => $civic->id,
        'service_date' => now()->subYear()->toDateString(),
        'kind' => 'oil_change',
        'next_due_on' => now()->subMonths(6)->toDateString(),
    ]);
    // Newer log for the same pair with a future-enough due date — supersedes.
    VehicleServiceLog::create([
        'vehicle_id' => $civic->id,
        'service_date' => now()->subMonths(2)->toDateString(),
        'kind' => 'oil_change',
        'next_due_on' => now()->addDays(60)->toDateString(),
    ]);

    $c = Livewire::test('attention-radar');
    expect($c->get('vehicleServicesDueSoon'))->toBe(0);
});

it('lists services with totals and filters by vehicle + kind', function () {
    authedInHousehold();
    $civic = Vehicle::create(['kind' => 'car', 'make' => 'Honda', 'model' => 'Civic']);
    $camry = Vehicle::create(['kind' => 'car', 'make' => 'Toyota', 'model' => 'Camry']);

    VehicleServiceLog::create(['vehicle_id' => $civic->id, 'service_date' => '2026-01-15', 'kind' => 'oil_change', 'cost' => 80, 'currency' => 'USD']);
    VehicleServiceLog::create(['vehicle_id' => $civic->id, 'service_date' => '2026-03-10', 'kind' => 'brakes', 'cost' => 420, 'currency' => 'USD']);
    VehicleServiceLog::create(['vehicle_id' => $camry->id, 'service_date' => '2026-02-02', 'kind' => 'oil_change', 'cost' => 95, 'currency' => 'USD']);

    $c = Livewire::test('vehicle-services-index');
    expect($c->get('logs')->count())->toBe(3)
        ->and($c->get('totalCost'))->toBe(595.0);

    $c->set('vehicleFilter', $civic->id);
    expect($c->get('logs')->count())->toBe(2)
        ->and($c->get('totalCost'))->toBe(500.0);

    $c->set('kindFilter', 'oil_change');
    expect($c->get('logs')->count())->toBe(1)
        ->and($c->get('totalCost'))->toBe(80.0);
});

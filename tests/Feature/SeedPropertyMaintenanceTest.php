<?php

use App\Models\Property;
use App\Models\RecurringRule;

it('seeds the maintenance template catalog against a property', function () {
    authedInHousehold();

    $p = Property::create([
        'kind' => 'home', 'name' => 'SF Apartment',
        'acquired_on' => now()->subYears(2)->toDateString(),
    ]);

    $this->artisan('property:seed-maintenance', ['property' => $p->id])
        ->assertSuccessful();

    $rules = RecurringRule::where('subject_type', Property::class)
        ->where('subject_id', $p->id)
        ->where('kind', 'maintenance')
        ->get();

    expect($rules->count())->toBeGreaterThanOrEqual(10)
        ->and($rules->pluck('title')->all())->toContain('HVAC air filter replacement')
        ->and($rules->pluck('title')->all())->toContain('Gutter cleaning');
});

it('is idempotent — rerunning skips existing maintenance rules', function () {
    authedInHousehold();

    $p = Property::create(['kind' => 'home', 'name' => 'Home', 'acquired_on' => now()->toDateString()]);

    $this->artisan('property:seed-maintenance', ['property' => $p->id])->assertSuccessful();
    $countAfterFirst = RecurringRule::where('subject_id', $p->id)->count();

    $this->artisan('property:seed-maintenance', ['property' => $p->id])->assertSuccessful();
    $countAfterSecond = RecurringRule::where('subject_id', $p->id)->count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});

it('refuses to run against a missing property id', function () {
    authedInHousehold();

    $this->artisan('property:seed-maintenance', ['property' => 99999])
        ->assertFailed();
});

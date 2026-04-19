<?php

use App\Models\Appointment;
use App\Models\HealthProvider;
use App\Models\User;
use Livewire\Livewire;

it('creates an appointment via the Inspector with subject = current user', function () {
    $user = authedInHousehold();
    $provider = HealthProvider::create(['name' => 'Dr Chen', 'specialty' => 'dentist']);

    Livewire::test('inspector')
        ->call('openInspector', 'appointment')
        ->set('appointment_purpose', 'Cleaning')
        ->set('appointment_starts_at', '2026-05-01T10:00')
        ->set('appointment_ends_at', '2026-05-01T10:45')
        ->set('appointment_location', 'Downtown office')
        ->set('appointment_provider_id', $provider->id)
        ->set('appointment_self_subject', true)
        ->call('save');

    $a = Appointment::firstOrFail();
    expect($a->purpose)->toBe('Cleaning')
        ->and($a->starts_at->format('Y-m-d H:i'))->toBe('2026-05-01 10:00')
        ->and($a->provider_id)->toBe($provider->id)
        ->and($a->state)->toBe('scheduled')
        ->and($a->subject_type)->toBe(User::class)
        ->and($a->subject_id)->toBe($user->id);
});

it('edits an existing appointment via the Inspector', function () {
    authedInHousehold();

    $a = Appointment::create([
        'purpose' => 'Original', 'starts_at' => '2026-05-01 09:00:00',
        'state' => 'scheduled',
    ]);

    Livewire::test('inspector')
        ->call('openInspector', 'appointment', $a->id)
        ->assertSet('appointment_purpose', 'Original')
        ->set('appointment_purpose', 'Rescheduled follow-up')
        ->set('appointment_state', 'completed')
        ->call('save');

    $fresh = $a->fresh();
    expect($fresh->purpose)->toBe('Rescheduled follow-up')
        ->and($fresh->state)->toBe('completed');
});

it('rejects an appointment with no starts_at', function () {
    authedInHousehold();

    Livewire::test('inspector')
        ->call('openInspector', 'appointment')
        ->set('appointment_purpose', 'No time given')
        ->call('save')
        ->assertHasErrors(['appointment_starts_at']);

    expect(Appointment::count())->toBe(0);
});

it('exposes the appointment option in the type picker', function () {
    authedInHousehold();

    Livewire::test('inspector')
        ->call('openInspector')
        ->assertSee(__('Appointment'))
        ->assertSeeHtml("openInspector('appointment')");
});

<?php

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\HealthProvider;
use App\Models\Prescription;
use App\Models\User;

it('renders the Providers drill-down with a specialty filter', function () {
    authedInHousehold();

    $contact = Contact::create(['kind' => 'person', 'display_name' => 'Dr Chen', 'phones' => ['555-0100']]);
    HealthProvider::create(['contact_id' => $contact->id, 'name' => 'Dr Sarah Chen', 'specialty' => 'primary_care']);
    HealthProvider::create(['name' => 'Smile Dental', 'specialty' => 'dentist']);

    $this->get('/health/providers')
        ->assertOk()
        ->assertSee('Dr Sarah Chen')
        ->assertSee('Smile Dental');

    $this->get('/health/providers?specialty=dentist')
        ->assertSee('Smile Dental')
        ->assertDontSee('Dr Sarah Chen');
});

it('renders the Prescriptions drill-down', function () {
    $user = authedInHousehold();

    Prescription::create([
        'subject_type' => User::class,
        'subject_id' => $user->id,
        'name' => 'Lisinopril',
        'dosage' => '10mg',
        'schedule' => 'daily',
        'refills_left' => 3,
        'next_refill_on' => now()->addDays(5)->toDateString(),
    ]);

    $this->get('/health/prescriptions')
        ->assertOk()
        ->assertSee('Lisinopril')
        ->assertSee('10mg');
});

it('renders the Appointments drill-down upcoming view', function () {
    $user = authedInHousehold();
    $provider = HealthProvider::create(['name' => 'Dr Chen', 'specialty' => 'primary_care']);

    Appointment::create([
        'provider_id' => $provider->id,
        'subject_type' => User::class,
        'subject_id' => $user->id,
        'purpose' => 'Annual physical',
        'starts_at' => now()->addDays(7)->setTime(9, 30),
        'ends_at' => now()->addDays(7)->setTime(10, 15),
        'location' => 'Clinic',
    ]);

    $this->get('/health/appointments')
        ->assertOk()
        ->assertSee('Dr Chen')
        ->assertSee('Clinic');
});

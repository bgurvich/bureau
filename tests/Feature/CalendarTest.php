<?php

use App\Models\Account;
use App\Models\Appointment;
use App\Models\Contract;
use App\Models\Document;
use App\Models\InventoryItem;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('renders the calendar month view with mixed date-bearing records', function () {
    CarbonImmutable::setTestNow('2026-04-15 12:00:00');
    $user = authedInHousehold();

    Task::create(['title' => 'Call dentist', 'due_at' => '2026-04-20 10:00:00', 'state' => 'open', 'assigned_user_id' => $user->id]);
    Meeting::create(['title' => '1:1 with manager', 'starts_at' => '2026-04-22 14:00:00', 'ends_at' => '2026-04-22 15:00:00']);
    Appointment::create(['purpose' => 'Checkup', 'starts_at' => '2026-04-18 09:30:00', 'ends_at' => '2026-04-18 10:00:00', 'state' => 'scheduled']);
    Document::create(['kind' => 'passport', 'holder_user_id' => $user->id, 'label' => 'US Passport', 'expires_on' => '2026-04-25']);
    Contract::create(['title' => 'Gym', 'kind' => 'subscription', 'state' => 'active', 'starts_on' => '2025-01-01', 'ends_on' => '2026-04-30']);
    InventoryItem::create(['name' => 'Samsung TV', 'category' => 'electronic', 'warranty_expires_on' => '2026-04-10']);
    Vehicle::create(['kind' => 'car', 'make' => 'Honda', 'model' => 'Civic', 'license_plate' => 'ABC123', 'registration_expires_on' => '2026-04-28', 'primary_user_id' => $user->id]);
    Account::create(['type' => 'gift_card', 'name' => 'Amazon GC', 'currency' => 'USD', 'opening_balance' => 50, 'expires_on' => '2026-04-29', 'is_active' => true]);

    $this->get('/calendar')
        ->assertOk()
        ->assertSee('April 2026')
        ->assertSee('Call dentist')
        ->assertSee('1:1 with manager')
        ->assertSee('Checkup')
        ->assertSee('US Passport')
        ->assertSee('Gym')
        ->assertSee('Samsung TV')
        ->assertSee('Honda Civic')
        ->assertSee('Amazon GC');

    CarbonImmutable::setTestNow();
});

it('navigates month cursor forward and back', function () {
    CarbonImmutable::setTestNow('2026-04-15 12:00:00');
    authedInHousehold();

    Livewire::test('calendar-index')
        ->call('go', 1)
        ->assertSet('cursor', '2026-05')
        ->call('go', -2)
        ->assertSet('cursor', '2026-03')
        ->call('today')
        ->assertSet('cursor', '');

    CarbonImmutable::setTestNow();
});

it('excludes done tasks and cancelled meetings', function () {
    CarbonImmutable::setTestNow('2026-04-15 12:00:00');
    $user = authedInHousehold();

    Task::create(['title' => 'Already done', 'due_at' => '2026-04-18 10:00:00', 'state' => 'done', 'assigned_user_id' => $user->id, 'completed_at' => now()]);
    Meeting::create(['title' => 'Cancelled lunch', 'starts_at' => '2026-04-19 12:00:00', 'ends_at' => '2026-04-19 13:00:00', 'status' => 'cancelled']);

    $this->get('/calendar')
        ->assertOk()
        ->assertDontSee('Already done')
        ->assertDontSee('Cancelled lunch');

    CarbonImmutable::setTestNow();
});

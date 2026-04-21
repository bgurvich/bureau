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

    // Cursor is a full YYYY-MM-DD anchor since week/day views landed. Month
    // `go(n)` steps month-by-month via addMonthsNoOverflow.
    Livewire::test('calendar-index')
        ->assertSet('view', 'month')
        ->call('go', 1)
        ->assertSet('cursor', '2026-05-15')
        ->call('go', -2)
        ->assertSet('cursor', '2026-03-15')
        ->call('today')
        ->assertSet('cursor', '');

    CarbonImmutable::setTestNow();
});

it('switches to week view and navigates by week', function () {
    CarbonImmutable::setTestNow('2026-04-15 12:00:00');
    $user = authedInHousehold();

    Meeting::create(['title' => 'Weekly sync', 'starts_at' => '2026-04-15 10:00:00', 'ends_at' => '2026-04-15 11:00:00']);
    Task::create(['title' => 'Ship feature', 'due_at' => '2026-04-17 17:00:00', 'state' => 'open', 'assigned_user_id' => $user->id]);

    Livewire::test('calendar-index')
        ->call('setView', 'week')
        ->assertSet('view', 'week')
        ->assertSee('Weekly sync')
        ->assertSee('Ship feature')
        ->call('go', 1)
        ->assertSet('cursor', '2026-04-22')
        ->call('go', -1)
        ->assertSet('cursor', '2026-04-15');

    CarbonImmutable::setTestNow();
});

it('switches to day view and navigates by day', function () {
    CarbonImmutable::setTestNow('2026-04-15 12:00:00');
    authedInHousehold();

    Meeting::create(['title' => 'Morning standup', 'starts_at' => '2026-04-15 09:00:00', 'ends_at' => '2026-04-15 09:30:00']);
    Document::create(['kind' => 'passport', 'label' => 'Expires today', 'expires_on' => '2026-04-15']);
    // Next-day event must NOT appear in today's day view.
    Meeting::create(['title' => 'Tomorrow meeting', 'starts_at' => '2026-04-16 10:00:00', 'ends_at' => '2026-04-16 11:00:00']);

    Livewire::test('calendar-index')
        ->call('setView', 'day')
        ->assertSet('view', 'day')
        ->assertSee('Morning standup')
        ->assertSee('Expires today')
        ->assertDontSee('Tomorrow meeting')
        ->call('go', 1)
        ->assertSet('cursor', '2026-04-16')
        ->assertSee('Tomorrow meeting')
        ->assertDontSee('Morning standup');

    CarbonImmutable::setTestNow();
});

it('rejects unknown view values', function () {
    CarbonImmutable::setTestNow('2026-04-15 12:00:00');
    authedInHousehold();

    Livewire::test('calendar-index')
        ->call('setView', 'year')
        ->assertSet('view', 'month'); // unchanged

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

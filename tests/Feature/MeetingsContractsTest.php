<?php

use App\Models\Contract;
use App\Models\Meeting;

it('renders the Meetings drill-down with upcoming rows', function () {
    authedInHousehold();

    Meeting::create([
        'title' => 'Dentist appointment',
        'starts_at' => now()->addDays(3)->setTime(10, 30),
        'ends_at' => now()->addDays(3)->setTime(11, 0),
        'location' => 'SF Dental',
    ]);

    $this->get('/meetings')
        ->assertOk()
        ->assertSee('Dentist appointment')
        ->assertSee('SF Dental');
});

it('switches to past meetings via the range filter', function () {
    authedInHousehold();

    Meeting::create([
        'title' => 'Quarterly review',
        'starts_at' => now()->subDays(10)->setTime(14, 0),
        'ends_at' => now()->subDays(10)->setTime(15, 0),
    ]);

    $this->get('/meetings?range=past')
        ->assertOk()
        ->assertSee('Quarterly review');
});

it('renders the Contracts drill-down with expiring highlights', function () {
    authedInHousehold();

    Contract::create([
        'kind' => 'subscription',
        'title' => 'Netflix',
        'starts_on' => now()->subYear()->toDateString(),
        'ends_on' => now()->addDays(15)->toDateString(),
        'monthly_cost_amount' => 15.49,
        'monthly_cost_currency' => 'USD',
        'state' => 'active',
    ]);

    $this->get('/contracts')
        ->assertOk()
        ->assertSee('Netflix')
        ->assertSee('15.49');
});

it('filters contracts by kind', function () {
    authedInHousehold();

    Contract::create(['kind' => 'subscription', 'title' => 'Spotify', 'state' => 'active']);
    Contract::create(['kind' => 'lease', 'title' => 'Apartment lease', 'state' => 'active']);

    $this->get('/contracts?kind=lease')
        ->assertOk()
        ->assertSee('Apartment lease')
        ->assertDontSee('Spotify');
});

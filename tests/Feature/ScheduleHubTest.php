<?php

declare(strict_types=1);

use Livewire\Livewire;

it('renders the schedule hub with the Calendar tab by default', function () {
    authedInHousehold();

    Livewire::test('schedule-hub')
        ->assertSet('tab', 'calendar')
        ->assertSee(__('Calendar'))
        ->assertSee(__('Meetings'));
});

it('switches between schedule tabs', function () {
    authedInHousehold();

    Livewire::test('schedule-hub')
        ->call('setTab', 'meetings')
        ->assertSet('tab', 'meetings')
        ->call('setTab', 'calendar')
        ->assertSet('tab', 'calendar');
});

it('refuses unknown tab values', function () {
    authedInHousehold();

    Livewire::test('schedule-hub')
        ->call('setTab', 'bogus')
        ->assertSet('tab', 'calendar');
});

it('answers at /schedule with 200 and keeps the deep-link routes alive', function () {
    authedInHousehold();

    $this->get(route('life.schedule'))->assertOk();
    $this->get(route('calendar.index'))->assertOk();
    $this->get(route('calendar.tasks'))->assertOk();
    $this->get(route('calendar.meetings'))->assertOk();
    $this->get(route('life.checklists.index'))->assertOk();
});

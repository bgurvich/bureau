<?php

use App\Models\Contact;
use Livewire\Livewire;

it('renders empty state when no birthdays are within the window', function () {
    authedInHousehold();
    Contact::create(['display_name' => 'Far birthday', 'birthday' => now()->addMonths(6)->toDateString()]);

    Livewire::test('relationships-radar')
        ->assertSet('contactsCount', 1)
        ->assertSee(__('No birthdays in the next 45 days.'));
});

it('surfaces upcoming birthdays sorted by next occurrence', function () {
    authedInHousehold();
    Contact::create(['display_name' => 'Early', 'birthday' => now()->addDays(5)->toDateString()]);
    Contact::create(['display_name' => 'Later', 'birthday' => now()->addDays(35)->toDateString()]);
    Contact::create(['display_name' => 'Far future', 'birthday' => now()->addDays(90)->toDateString()]);

    $component = Livewire::test('relationships-radar');
    $upcoming = $component->instance()->upcoming;

    expect($upcoming->count())->toBe(2)
        ->and($upcoming[0]->display_name)->toBe('Early')
        ->and($upcoming[1]->display_name)->toBe('Later');
});

it('detects today as a birthday match', function () {
    authedInHousehold();
    Contact::create(['display_name' => 'Today', 'birthday' => now()->toDateString()]);

    Livewire::test('relationships-radar')
        ->assertSet('todays', fn ($c) => $c->count() === 1 && $c->first()->display_name === 'Today');
});

it('counts total contacts separately from the birthday window', function () {
    authedInHousehold();
    Contact::create(['display_name' => 'A', 'birthday' => now()->addYears(5)->toDateString()]);
    Contact::create(['display_name' => 'B']);
    Contact::create(['display_name' => 'C', 'birthday' => now()->addDays(10)->toDateString()]);

    Livewire::test('relationships-radar')
        ->assertSet('contactsCount', 3);
});

<?php

use Livewire\Livewire;

it('renders the profile page with the user\'s current values', function () {
    authedInHousehold();

    $this->get('/profile')
        ->assertOk()
        ->assertSee('Profile')
        ->assertSee('Language')
        ->assertSee('Timezone')
        ->assertSee('Date format');
});

it('saves profile changes', function () {
    $user = authedInHousehold();

    Livewire::test('profile')
        ->set('name', 'Alicia')
        ->set('timezone', 'Europe/Berlin')
        ->set('date_format', 'd.m.Y')
        ->set('time_format', 'h:i A')
        ->set('week_starts_on', 1)
        ->set('theme', 'dark')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    $fresh = $user->fresh();
    expect($fresh->name)->toBe('Alicia')
        ->and($fresh->timezone)->toBe('Europe/Berlin')
        ->and($fresh->date_format)->toBe('d.m.Y')
        ->and($fresh->time_format)->toBe('h:i A')
        ->and((int) $fresh->week_starts_on)->toBe(1)
        ->and($fresh->theme)->toBe('dark');
});

it('rejects invalid timezones', function () {
    authedInHousehold();

    Livewire::test('profile')
        ->set('timezone', 'Not/A/Zone')
        ->call('save')
        ->assertHasErrors(['timezone']);
});

it('rejects invalid themes', function () {
    authedInHousehold();

    Livewire::test('profile')
        ->set('theme', 'neon')
        ->call('save')
        ->assertHasErrors(['theme']);
});

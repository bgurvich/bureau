<?php

use App\Models\Integration;
use App\Models\UserNotificationPreference;
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

it('renders the notification preferences matrix on the profile page', function () {
    authedInHousehold();

    $this->get('/profile')
        ->assertOk()
        ->assertSee(__('Notification preferences'))
        ->assertSee(__('Bills'))
        ->assertSee('Email');
});

it('toggling a notification preference persists an opt-out', function () {
    $user = authedInHousehold();

    $comp = Livewire::test('profile')
        ->call('togglePreference', 'task_reminder', 'email');

    $row = UserNotificationPreference::where('user_id', $user->id)
        ->where('kind', 'task_reminder')
        ->where('channel', 'email')
        ->first();

    expect($row)->not->toBeNull()
        ->and((bool) $row->enabled)->toBeFalse();

    $comp->call('togglePreference', 'task_reminder', 'email');
    expect((bool) $row->fresh()->enabled)->toBeTrue();
});

it('shows personal mail / calendar integrations on the profile page', function () {
    authedInHousehold();
    Integration::forceCreate([
        'provider' => 'gmail', 'kind' => 'mail',
        'label' => 'me@example.com',
        'credentials' => ['refresh_token' => 'x'],
        'settings' => [],
        'status' => 'active',
    ]);

    $this->get('/profile')
        ->assertOk()
        ->assertSee(__('Personal integrations'))
        ->assertSee('me@example.com')
        ->assertSee(__('Connect Gmail'));
});

it('omits household-level integrations from the profile page', function () {
    authedInHousehold();
    Integration::forceCreate([
        'provider' => 'paypal', 'kind' => 'bank',
        'label' => 'Household PayPal',
        'credentials' => ['client_id' => 'x'],
        'settings' => [],
        'status' => 'active',
    ]);

    $this->get('/profile')
        ->assertOk()
        ->assertDontSee('Household PayPal');
});

it('disconnects a personal mail integration from the profile controller', function () {
    authedInHousehold();
    $int = Integration::forceCreate([
        'provider' => 'gmail', 'kind' => 'mail',
        'label' => 'me@example.com',
        'credentials' => ['refresh_token' => 'x'],
        'settings' => [],
        'status' => 'active',
    ]);

    Livewire::test('profile')->call('disconnectIntegration', $int->id);
    expect(Integration::where('id', $int->id)->exists())->toBeFalse();
});

it('refuses to disconnect a household integration from the profile controller', function () {
    authedInHousehold();
    $int = Integration::forceCreate([
        'provider' => 'paypal', 'kind' => 'bank',
        'label' => 'Household PayPal',
        'credentials' => ['client_id' => 'x'],
        'settings' => [],
        'status' => 'active',
    ]);

    Livewire::test('profile')->call('disconnectIntegration', $int->id);
    expect(Integration::where('id', $int->id)->exists())->toBeTrue();
});

<?php

use App\Models\Integration;
use Livewire\Livewire;

it('renders the settings page', function () {
    authedInHousehold();
    $this->get(route('settings'))
        ->assertOk()
        ->assertSee(__('Settings'))
        ->assertSee(__('Integrations'))
        ->assertSee(__('Backups'));
});

it('settings page lists connected integrations and lets user disconnect', function () {
    authedInHousehold();
    $int = Integration::forceCreate([
        'provider' => 'gmail', 'kind' => 'mail',
        'label' => 'me@example.com',
        'credentials' => ['refresh_token' => 'x', 'access_token' => 'y', 'access_token_expires_at' => time() + 3600],
        'settings' => [],
        'status' => 'active',
    ]);

    $response = $this->get(route('settings'))->assertOk();
    $response->assertSee('me@example.com');

    Livewire::test('settings-index')->call('disconnectIntegration', $int->id);
    expect(Integration::where('id', $int->id)->exists())->toBeFalse();
});

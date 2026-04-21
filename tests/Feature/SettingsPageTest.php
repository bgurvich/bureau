<?php

use App\Models\Integration;
use Livewire\Livewire;

it('renders the settings page', function () {
    authedInHousehold();
    $this->get(route('settings'))
        ->assertOk()
        ->assertSee(__('Settings'))
        ->assertSee(__('Household integrations'))
        ->assertSee(__('Outbound mail'))
        ->assertSee(__('Local AI (LM Studio)'))
        ->assertSee(__('Backups'));
});

it('lists household-level integrations and lets user disconnect', function () {
    authedInHousehold();
    $int = Integration::forceCreate([
        'provider' => 'paypal', 'kind' => 'bank',
        'label' => 'Household PayPal',
        'credentials' => ['client_id' => 'x', 'client_secret' => 'y'],
        'settings' => ['webhook_id' => 'WH-123'],
        'status' => 'active',
    ]);

    $response = $this->get(route('settings'))->assertOk();
    $response->assertSee('Household PayPal');

    Livewire::test('settings-index')->call('disconnectIntegration', $int->id);
    expect(Integration::where('id', $int->id)->exists())->toBeFalse();
});

it('hides personal mail / calendar integrations from the settings page', function () {
    authedInHousehold();
    Integration::forceCreate([
        'provider' => 'gmail', 'kind' => 'mail',
        'label' => 'me@example.com',
        'credentials' => ['refresh_token' => 'x'],
        'settings' => [],
        'status' => 'active',
    ]);

    $this->get(route('settings'))
        ->assertOk()
        ->assertDontSee('me@example.com');
});

it('refuses to disconnect a mail integration from the settings controller', function () {
    authedInHousehold();
    $int = Integration::forceCreate([
        'provider' => 'gmail', 'kind' => 'mail',
        'label' => 'me@example.com',
        'credentials' => ['refresh_token' => 'x'],
        'settings' => [],
        'status' => 'active',
    ]);

    Livewire::test('settings-index')->call('disconnectIntegration', $int->id);
    expect(Integration::where('id', $int->id)->exists())->toBeTrue();
});

it('reports outbound mail status from config', function () {
    authedInHousehold();
    config()->set('mail.default', 'postmark');
    config()->set('mail.from.address', 'hello@bureau.test');

    $this->get(route('settings'))
        ->assertOk()
        ->assertSee('postmark')
        ->assertSee('hello@bureau.test')
        ->assertSee('api.postmarkapp.com');
});

it('shows local AI as disabled when LM Studio is off', function () {
    authedInHousehold();
    config()->set('services.lm_studio.enabled', false);

    $this->get(route('settings'))
        ->assertOk()
        ->assertSee(__('disabled'));
});

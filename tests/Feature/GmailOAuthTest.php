<?php

declare(strict_types=1);

use App\Models\Integration;
use App\Support\CurrentHousehold;
use App\Support\Mail\GmailProvider;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('services.google.client_id', 'test-client-id');
    config()->set('services.google.client_secret', 'test-client-secret');
});

it('OAuth connect route redirects to Google with the expected params', function () {
    $user = authedInHousehold();
    $this->actingAs($user);

    $response = $this->get(route('integrations.gmail.connect'));

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth')
        ->and($location)->toContain('client_id=test-client-id')
        ->and($location)->toContain('access_type=offline')
        ->and($location)->toContain('prompt=consent');
});

it('OAuth callback rejects a mismatched state (CSRF guard)', function () {
    $user = authedInHousehold();
    $this->actingAs($user);

    // Prime session with one state, hit callback with a different one.
    session(['gmail_oauth_state' => 'real-state']);

    $this->get(route('integrations.gmail.callback', ['state' => 'forged-state', 'code' => 'x']))
        ->assertStatus(400);
});

it('OAuth callback aborts when Google omits the refresh_token', function () {
    $user = authedInHousehold();
    $this->actingAs($user);

    session(['gmail_oauth_state' => 's']);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'a', 'expires_in' => 3600,
            // no refresh_token
        ]),
    ]);

    $this->get(route('integrations.gmail.callback', ['state' => 's', 'code' => 'c']))
        ->assertStatus(400);
});

it('OAuth callback stores an active Integration on success', function () {
    $user = authedInHousehold();
    $this->actingAs($user);

    session(['gmail_oauth_state' => 's']);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600,
        ]),
        'googleapis.com/oauth2/v2/userinfo' => Http::response(['email' => 'me@example.com']),
    ]);

    $this->get(route('integrations.gmail.callback', ['state' => 's', 'code' => 'c']))
        ->assertRedirect();

    $integration = Integration::withoutGlobalScope('household')->firstOrFail();
    expect($integration->provider)->toBe('gmail')
        ->and($integration->kind)->toBe('mail')
        ->and($integration->label)->toBe('me@example.com')
        ->and($integration->status)->toBe('active')
        ->and($integration->credentials['refresh_token'])->toBe('r');
});

it('GmailProvider marks integration status=error when Google rejects the refresh_token', function () {
    $user = authedInHousehold();
    $integration = Integration::forceCreate([
        'household_id' => $user->defaultHousehold->id,
        'provider' => 'gmail',
        'kind' => 'mail',
        'label' => 'Gmail',
        'credentials' => ['refresh_token' => 'bad-refresh', 'access_token' => '', 'access_token_expires_at' => 0],
        'status' => 'active',
    ]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    // Force the provider to try refresh + fail; it should swallow the error
    // and mark the integration for reconnection.
    iterator_to_array((new GmailProvider)->pullSince($integration->fresh()));

    $fresh = $integration->fresh();
    expect($fresh->status)->toBe('error')
        ->and($fresh->last_error)->toContain('reconnect');
});

it('Attention radar counts integrations in status=error', function () {
    authedInHousehold();

    Integration::forceCreate([
        'household_id' => CurrentHousehold::id(),
        'provider' => 'gmail', 'kind' => 'mail', 'label' => 'Gmail',
        'credentials' => [], 'status' => 'error', 'last_error' => 'Refresh rejected',
    ]);
    Integration::forceCreate([
        'household_id' => CurrentHousehold::id(),
        'provider' => 'fastmail', 'kind' => 'mail', 'label' => 'Fastmail',
        'credentials' => [], 'status' => 'active',
    ]);

    $c = Livewire::test('attention-radar');
    expect($c->get('integrationsNeedingReconnect'))->toBe(1);
});

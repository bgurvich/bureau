<?php

use App\Models\User;
use Illuminate\Support\Str;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\Models\WebAuthnCredential;
use Livewire\Livewire;

it('exposes the passkey login button on the auth page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Sign in with a passkey');
});

it('exposes webauthn login-ceremony endpoints to guests', function () {
    // Options is a POST; expect a 200 (challenge issued) or 422 (no email) —
    // anything other than 404/405/302 proves the guest route is reachable.
    $response = $this->postJson(route('webauthn.login.options'));
    expect($response->status())->not->toBe(404)
        ->and($response->status())->not->toBe(405)
        ->and($response->status())->not->toBe(302);
});

it('blocks webauthn register endpoints for guests', function () {
    $this->postJson(route('webauthn.register.options'))->assertUnauthorized();
    $this->postJson(route('webauthn.register'), [])->assertUnauthorized();
});

it('issues a passkey-registration challenge to an authenticated user', function () {
    authedInHousehold();
    $response = $this->postJson(route('webauthn.register.options'));
    expect($response->status())->toBe(200);
    $payload = $response->json();
    expect($payload)->toHaveKeys(['challenge', 'rp', 'user', 'pubKeyCredParams']);
});

it('lists a users passkeys and lets them remove one', function () {
    $user = authedInHousehold();
    $credential = (new WebAuthnCredential)->forceFill([
        'id' => 'cred-'.bin2hex(random_bytes(8)),
        'authenticatable_type' => User::class,
        'authenticatable_id' => $user->id,
        'user_id' => (string) Str::uuid(),
        'counter' => 0,
        'rp_id' => 'localhost',
        'origin' => 'http://localhost',
        'aaguid' => '00000000-0000-0000-0000-000000000000',
        'attestation_format' => 'none',
        'public_key' => 'test-key',
        'alias' => 'My laptop',
    ]);
    $credential->save();

    $component = Livewire::test('passkey-manager');
    $component->assertSee('My laptop');

    $component->call('delete', $credential->id);
    expect(WebAuthnCredential::where('id', $credential->id)->exists())->toBeFalse();
});

it('does not let one user delete another users passkey', function () {
    $intruder = authedInHousehold();
    $victim = User::factory()->create();
    $credential = (new WebAuthnCredential)->forceFill([
        'id' => 'cred-'.bin2hex(random_bytes(8)),
        'authenticatable_type' => User::class,
        'authenticatable_id' => $victim->id,
        'user_id' => (string) Str::uuid(),
        'counter' => 0,
        'rp_id' => 'localhost',
        'origin' => 'http://localhost',
        'aaguid' => '00000000-0000-0000-0000-000000000000',
        'attestation_format' => 'none',
        'public_key' => 'test-key',
        'alias' => 'victim laptop',
    ]);
    $credential->save();

    Livewire::test('passkey-manager')->call('delete', $credential->id);
    expect(WebAuthnCredential::where('id', $credential->id)->exists())->toBeTrue();
});

it('auth provider is the webauthn-aware eloquent driver with password fallback', function () {
    expect(config('auth.providers.users.driver'))->toBe('eloquent-webauthn')
        ->and(config('auth.providers.users.password_fallback'))->toBeTrue();
});

it('User implements WebAuthnAuthenticatable and exposes the webauthn relation', function () {
    $user = authedInHousehold();
    expect($user)->toBeInstanceOf(WebAuthnAuthenticatable::class);
    // Method exists via the WebAuthnAuthentication trait:
    expect(method_exists($user, 'webAuthnCredentials'))->toBeTrue();
    expect($user->webAuthnCredentials)->toHaveCount(0);
});

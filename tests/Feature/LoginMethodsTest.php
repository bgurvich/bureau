<?php

use App\Mail\MagicLinkMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

beforeEach(function () {
    Mail::fake();
});

// ======================= MAGIC LINK =======================

it('magic-link request mails a signed URL to a registered user', function () {
    $user = User::create([
        'name' => 'Real user',
        'email' => 'user@example.com',
        'password' => 'irrelevant',
    ]);

    $this->post(route('magic-link.request'), ['email' => 'user@example.com'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('magic_link_sent');

    Mail::assertSent(MagicLinkMail::class, fn ($mail) => $mail->user->id === $user->id);
});

it('magic-link request silently no-ops on unknown email (no enumeration)', function () {
    $this->post(route('magic-link.request'), ['email' => 'unknown@example.com'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('magic_link_sent');

    Mail::assertNothingSent();
});

it('valid magic-link consume logs the user in and lands on the dashboard', function () {
    $user = User::create([
        'name' => 'Magic user',
        'email' => 'magic@example.com',
        'password' => 'irrelevant',
    ]);

    $url = URL::temporarySignedRoute(
        'magic-link.consume',
        now()->addMinutes(15),
        ['user' => $user->id]
    );

    $this->get($url)->assertRedirect(route('dashboard'));
    expect(auth()->id())->toBe($user->id);
});

it('magic-link rejects a tampered URL', function () {
    $user = User::create([
        'name' => 'Magic',
        'email' => 'tamper@example.com',
        'password' => 'irrelevant',
    ]);

    // Unsigned URL — no signature at all
    $this->get(route('magic-link.consume', ['user' => $user->id]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email']);

    expect(auth()->check())->toBeFalse();
});

// ======================= SOCIAL OAUTH =======================

it('social-redirect 404s when the provider is not configured', function () {
    // No client_id in config → treat as unsupported
    config()->set('services.google.client_id', '');

    $this->get(route('social.redirect', ['provider' => 'google']))
        ->assertStatus(404);
});

it('social-callback rejects an unknown email instead of auto-creating an account', function () {
    config()->set('services.github.client_id', 'cid');
    config()->set('services.github.client_secret', 'secret');

    $social = Mockery::mock(SocialiteUser::class);
    $social->shouldReceive('getEmail')->andReturn('new@example.com');
    $social->shouldReceive('getName')->andReturn('New User');

    Socialite::shouldReceive('driver->user')->once()->andReturn($social);

    $this->get(route('social.callback', ['provider' => 'github']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email']);

    expect(auth()->check())->toBeFalse()
        ->and(User::where('email', 'new@example.com')->exists())->toBeFalse();
});

it('social-callback logs in existing user matched by email', function () {
    config()->set('services.github.client_id', 'cid');
    config()->set('services.github.client_secret', 'secret');

    $existing = User::create([
        'name' => 'Existing user',
        'email' => 'existing@example.com',
        'password' => 'irrelevant',
    ]);

    $social = Mockery::mock(SocialiteUser::class);
    $social->shouldReceive('getEmail')->andReturn('existing@example.com');
    $social->shouldReceive('getName')->andReturn('Ignored Different Name');

    Socialite::shouldReceive('driver->user')->once()->andReturn($social);

    $this->get(route('social.callback', ['provider' => 'github']))
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($existing->id)
        ->and(User::count())->toBe(1);
});

it('social-callback redirects with error when provider returns no email', function () {
    config()->set('services.github.client_id', 'cid');
    config()->set('services.github.client_secret', 'secret');

    $social = Mockery::mock(SocialiteUser::class);
    $social->shouldReceive('getEmail')->andReturn(null);
    $social->shouldReceive('getName')->andReturn('Anon');

    Socialite::shouldReceive('driver->user')->once()->andReturn($social);

    $this->get(route('social.callback', ['provider' => 'github']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email']);

    expect(auth()->check())->toBeFalse();
});

it('social-callback redirects with error on provider exception', function () {
    config()->set('services.github.client_id', 'cid');
    config()->set('services.github.client_secret', 'secret');

    Socialite::shouldReceive('driver->user')->once()
        ->andThrow(new RuntimeException('oauth provider blew up'));

    $this->get(route('social.callback', ['provider' => 'github']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email']);
});

it('login page shows configured social buttons and hides unconfigured', function () {
    config()->set('services.google.client_id', 'cid');
    config()->set('services.github.client_id', '');
    config()->set('services.microsoft.client_id', '');
    config()->set('services.apple.client_id', '');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Continue with Google')
        ->assertDontSee('Continue with GitHub')
        ->assertDontSee('Continue with Microsoft')
        ->assertDontSee('Continue with Apple');
});

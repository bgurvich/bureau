<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\MagicLink;
use Illuminate\Support\Facades\URL;

it('MagicLink::to builds a signed URL that embeds the user and destination', function () {
    $user = User::factory()->create();

    $url = MagicLink::to($user, 'dashboard');

    expect($url)->toStartWith(config('app.url'))
        ->and(parse_url($url, PHP_URL_PATH))->toContain('/login/magic/'.$user->id)
        ->and($url)->toContain('signature=')
        ->and($url)->toContain('expires=')
        ->and(urldecode(parse_url($url, PHP_URL_QUERY) ?: ''))->toContain('redirect=/');
});

it('consume() honours a same-origin redirect after login', function () {
    $user = User::factory()->create();

    $url = URL::temporarySignedRoute(
        'magic-link.consume',
        now()->addMinutes(15),
        ['user' => $user->id, 'redirect' => '/bills'],
    );

    $this->get($url)
        ->assertRedirect('/bills');
    expect(auth()->id())->toBe($user->id);
});

it('consume() refuses a protocol-relative redirect (open-redirect guard)', function () {
    $user = User::factory()->create();

    $url = URL::temporarySignedRoute(
        'magic-link.consume',
        now()->addMinutes(15),
        ['user' => $user->id, 'redirect' => '//evil.example.com/pwn'],
    );

    // Falls back to the default dashboard intent rather than redirecting
    // off-origin — signature is valid but the redirect target isn't.
    $this->get($url)
        ->assertRedirect(route('dashboard'));
    expect(auth()->id())->toBe($user->id);
});

it('consume() refuses a full-URL redirect (open-redirect guard)', function () {
    $user = User::factory()->create();

    $url = URL::temporarySignedRoute(
        'magic-link.consume',
        now()->addMinutes(15),
        ['user' => $user->id, 'redirect' => 'https://evil.example.com/'],
    );

    $this->get($url)
        ->assertRedirect(route('dashboard'));
});

it('MagicLink::toPath wraps an arbitrary app path', function () {
    $user = User::factory()->create();

    $url = MagicLink::toPath($user, '/media?mime=pdf');

    expect(urldecode(parse_url($url, PHP_URL_QUERY) ?: ''))->toContain('redirect=/media?mime=pdf');
});

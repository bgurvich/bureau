<?php

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

it('records a successful password login', function () {
    $user = User::factory()->create([
        'email' => 'ada@example.com',
        'password' => bcrypt('secret-pass'),
    ]);

    Livewire::test('login')
        ->set('email', $user->email)
        ->set('password', 'secret-pass')
        ->call('submit');

    $ev = LoginEvent::first();
    expect($ev)->not->toBeNull()
        ->and($ev->method)->toBe('password')
        ->and($ev->succeeded)->toBeTrue()
        ->and($ev->user_id)->toBe($user->id)
        ->and($ev->email)->toBe('ada@example.com');
});

it('records a failed password login attempt with the attempted email', function () {
    User::factory()->create([
        'email' => 'ada@example.com',
        'password' => bcrypt('right-pass'),
    ]);

    Livewire::test('login')
        ->set('email', 'ada@example.com')
        ->set('password', 'wrong-pass')
        ->call('submit');

    $ev = LoginEvent::first();
    expect($ev)->not->toBeNull()
        ->and($ev->method)->toBe('password')
        ->and($ev->succeeded)->toBeFalse()
        ->and($ev->email)->toBe('ada@example.com')
        ->and($ev->reason)->toBe('invalid-credentials')
        ->and($ev->user_id)->toBeNull();
});

it('records a successful magic-link login', function () {
    Mail::fake();
    $user = User::factory()->create(['email' => 'linus@example.com']);

    $this->post(route('magic-link.request'), ['email' => $user->email]);

    // Grab the URL that would have been mailed — simulate the click by
    // generating a matching signed URL in-test (same TTL/route).
    $url = URL::temporarySignedRoute(
        'magic-link.consume',
        now()->addMinutes(15),
        ['user' => $user->id]
    );

    $this->get($url)->assertRedirect(route('dashboard'));

    $ev = LoginEvent::where('method', 'magic_link')->latest('id')->first();
    expect($ev)->not->toBeNull()
        ->and($ev->succeeded)->toBeTrue()
        ->and($ev->user_id)->toBe($user->id);
});

it('records a failed magic-link attempt with an invalid signature', function () {
    $user = User::factory()->create();
    $this->get(route('magic-link.consume', ['user' => $user->id]).'?signature=bogus');

    $ev = LoginEvent::where('method', 'magic_link')->first();
    expect($ev)->not->toBeNull()
        ->and($ev->succeeded)->toBeFalse()
        ->and($ev->reason)->toBe('invalid-signature');
});

it('shows recent sign-ins on the profile page', function () {
    $user = authedInHousehold();
    LoginEvent::create([
        'user_id' => $user->id,
        'email' => $user->email,
        'method' => 'password',
        'succeeded' => true,
        'ip' => '203.0.113.5',
        'user_agent' => 'TestAgent/1.0',
    ]);

    $this->get(route('profile'))
        ->assertOk()
        ->assertSee('Recent sign-ins')
        ->assertSee('203.0.113.5')
        ->assertSee('password');
});

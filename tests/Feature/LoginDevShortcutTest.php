<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

it('shows the dev-accounts pill row in local env and signs in on click', function () {
    app()->detectEnvironment(fn () => 'local');
    $user = User::factory()->create(['email' => 'dev@bureau.test']);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee(__('Dev accounts · click to sign in'))
        ->assertSee('dev@bureau.test');

    Livewire::test('login')
        ->call('devLogin', $user->id)
        ->assertRedirect(route('dashboard'));

    expect(Auth::id())->toBe($user->id);
});

it('devLogin is forbidden outside local env', function () {
    app()->detectEnvironment(fn () => 'production');
    $user = User::factory()->create();

    Livewire::test('login')
        ->call('devLogin', $user->id)
        ->assertForbidden();

    expect(Auth::check())->toBeFalse();
});

it('hides the dev shortcut outside local env', function () {
    app()->detectEnvironment(fn () => 'production');
    User::factory()->create();

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee(__('Dev accounts · click to sign in'));
});

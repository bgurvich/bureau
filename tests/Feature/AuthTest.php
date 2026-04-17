<?php

use App\Models\Household;
use App\Models\User;
use Livewire\Livewire;

it('redirects unauthenticated visitors from the dashboard to /login', function () {
    $this->get('/')->assertRedirect('/login');
});

it('renders the login page', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Sign in');
});

it('authenticates with valid credentials and lands on the dashboard', function () {
    $household = Household::create(['name' => 'Test', 'default_currency' => 'USD']);
    $user = User::create([
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => bcrypt('secret-1234'),
        'default_household_id' => $household->id,
    ]);
    $household->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

    Livewire::test('login')
        ->set('email', 'alice@example.com')
        ->set('password', 'secret-1234')
        ->call('submit')
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials', function () {
    $household = Household::create(['name' => 'Test', 'default_currency' => 'USD']);
    User::create([
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => bcrypt('secret-1234'),
        'default_household_id' => $household->id,
    ]);

    Livewire::test('login')
        ->set('email', 'alice@example.com')
        ->set('password', 'wrong')
        ->call('submit')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

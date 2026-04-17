<?php

use App\Models\Household;
use App\Models\User;

function login(): User
{
    $household = Household::create(['name' => 'Test', 'default_currency' => 'USD']);
    $user = User::create([
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => bcrypt('secret-1234'),
        'default_household_id' => $household->id,
    ]);
    $household->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
    test()->actingAs($user);

    return $user;
}

it('renders the dashboard for an authenticated user with a household', function () {
    login();

    $this->get('/')
        ->assertOk()
        ->assertSee('Bureau')
        ->assertSee('Money')
        ->assertSee('Commitments')
        ->assertSee('Attention')
        ->assertSee('Time tracker');
});

it('serves stub domain pages as 200 for an authenticated user', function () {
    login();

    foreach (['/accounts', '/transactions', '/tasks', '/meetings', '/contacts', '/contracts', '/documents', '/time/projects'] as $path) {
        $this->get($path)->assertOk();
    }
});

it('logs the user out on POST /logout', function () {
    login();
    $this->post('/logout')->assertRedirect('/login');
    expect(auth()->check())->toBeFalse();
});

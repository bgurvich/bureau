<?php

use App\Models\User;
use Livewire\Livewire;

/** User-menu tests expect the "Alice Example" initials ("AE"). */
function authedAliceExample(): User
{
    $user = authedInHousehold();
    $user->forceFill(['name' => 'Alice Example'])->save();

    return $user->fresh();
}

it('renders the user-menu trigger with initials and email', function () {
    $user = authedAliceExample();

    Livewire::test('user-menu')
        ->assertOk()
        ->assertSee($user->name)
        ->assertSee($user->email)
        ->assertSee('AE'); // initials of "Alice Example"
});

it('persists a theme change', function () {
    $user = authedAliceExample();

    Livewire::test('user-menu')
        ->call('setTheme', 'dark')
        ->assertSet('theme', 'dark');

    expect($user->fresh()->theme)->toBe('dark');
});

it('rejects unknown theme values', function () {
    $user = authedAliceExample();
    $user->forceFill(['theme' => 'system'])->save();

    Livewire::test('user-menu')
        ->call('setTheme', 'tangerine')
        ->assertSet('theme', 'system');

    expect($user->fresh()->theme)->toBe('system');
});

it('persists the retro theme', function () {
    $user = authedAliceExample();

    Livewire::test('user-menu')
        ->call('setTheme', 'retro')
        ->assertSet('theme', 'retro');

    expect($user->fresh()->theme)->toBe('retro');
});

it('persists the dusk theme — the warm-stone midtone option', function () {
    $user = authedAliceExample();

    Livewire::test('user-menu')
        ->call('setTheme', 'dusk')
        ->assertSet('theme', 'dusk');

    expect($user->fresh()->theme)->toBe('dusk');
});

it('rejects unknown locales', function () {
    $user = authedAliceExample();

    Livewire::test('user-menu')
        ->call('setLocale', 'xx');

    expect($user->fresh()->locale)->toBe('en');
});

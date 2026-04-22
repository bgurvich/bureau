<?php

declare(strict_types=1);

use App\Models\UserHubPreference;
use App\Support\HubTabMemory;
use Livewire\Livewire;

it('resolve() returns the URL tab when present', function () {
    authedInHousehold();
    expect(HubTabMemory::resolve('pets', 'vaccinations', 'pets'))->toBe('vaccinations');
});

it('resolve() returns the remembered tab when the URL is empty', function () {
    authedInHousehold();
    HubTabMemory::remember('pets', 'checkups');
    expect(HubTabMemory::resolve('pets', '', 'pets'))->toBe('checkups');
});

it('resolve() falls back to the default when the URL is empty and nothing is remembered', function () {
    authedInHousehold();
    expect(HubTabMemory::resolve('pets', '', 'pets'))->toBe('pets');
});

it('remember() upserts — same (user, household, hub) reuses the row', function () {
    authedInHousehold();

    HubTabMemory::remember('pets', 'vaccinations');
    HubTabMemory::remember('pets', 'checkups');
    HubTabMemory::remember('pets', 'pets');

    expect(UserHubPreference::where('hub_name', 'pets')->count())->toBe(1)
        ->and(UserHubPreference::where('hub_name', 'pets')->first()->active_tab)->toBe('pets');
});

it('preferences are scoped per user+household (cross-user / cross-household isolation)', function () {
    // User A in Household A sets pets→vaccinations.
    $userA = authedInHousehold('Alpha', 'a@example.com');
    HubTabMemory::remember('pets', 'vaccinations');

    // User B in their own household sets pets→checkups.
    $userB = authedInHousehold('Beta', 'b@example.com');
    HubTabMemory::remember('pets', 'checkups');

    // User A still sees their own pref.
    $this->actingAs($userA);
    \App\Support\CurrentHousehold::set($userA->defaultHousehold);
    expect(HubTabMemory::remembered('pets'))->toBe('vaccinations');

    // User B still sees theirs.
    $this->actingAs($userB);
    \App\Support\CurrentHousehold::set($userB->defaultHousehold);
    expect(HubTabMemory::remembered('pets'))->toBe('checkups');
});

it('pets-hub mount() restores the remembered tab when URL has no ?tab=', function () {
    authedInHousehold();
    HubTabMemory::remember('pets', 'vaccinations');

    Livewire::test('pets-hub')->assertSet('tab', 'vaccinations');
});

it('pets-hub setTab() persists the new tab for next visit', function () {
    authedInHousehold();

    Livewire::test('pets-hub')
        ->assertSet('tab', 'pets') // default, nothing stored yet
        ->call('setTab', 'checkups')
        ->assertSet('tab', 'checkups');

    expect(HubTabMemory::remembered('pets'))->toBe('checkups');
});

it('a URL ?tab= wins over any stored preference', function () {
    authedInHousehold();
    HubTabMemory::remember('pets', 'vaccinations');

    // Simulate a deep link with ?tab=checkups by setting the property
    // directly before mount's URL hydration finishes.
    Livewire::withQueryParams(['tab' => 'checkups'])
        ->test('pets-hub')
        ->assertSet('tab', 'checkups');
});

<?php

use App\Models\Contact;
use App\Models\Household;
use App\Models\OnlineAccount;
use App\Models\User;
use Livewire\Livewire;

it('renders the online accounts drill-down', function () {
    authedInHousehold();

    OnlineAccount::create([
        'service_name' => 'Gmail',
        'kind' => 'email',
        'url' => 'https://mail.google.com',
        'login_email' => 'user@example.com',
        'mfa_method' => 'totp',
        'importance_tier' => 'critical',
    ]);

    $this->get('/online-accounts')
        ->assertOk()
        ->assertSee('Gmail')
        ->assertSee('user@example.com')
        ->assertSee('critical');
});

it('filters online accounts by kind', function () {
    authedInHousehold();

    OnlineAccount::create(['service_name' => 'Netflix', 'kind' => 'streaming', 'importance_tier' => 'low']);
    OnlineAccount::create(['service_name' => 'Chase Bank', 'kind' => 'financial', 'importance_tier' => 'critical']);

    $this->get('/online-accounts?kind=financial')
        ->assertSee('Chase Bank')
        ->assertDontSee('Netflix');
});

it('creates an online account through the Inspector', function () {
    authedInHousehold();
    $spouse = Contact::create(['kind' => 'person', 'display_name' => 'Partner']);

    Livewire::test('inspector')
        ->call('openInspector', 'online_account')
        ->set('oa_service_name', 'GitHub')
        ->set('oa_kind', 'developer')
        ->set('oa_url', 'https://github.com/login')
        ->set('oa_login_email', 'me@example.com')
        ->set('oa_mfa_method', 'passkey')
        ->set('oa_importance_tier', 'high')
        ->set('oa_recovery_contact_id', $spouse->id)
        ->set('oa_in_case_of_pack', true)
        ->call('save')
        ->assertSet('open', false);

    $oa = OnlineAccount::firstWhere('service_name', 'GitHub');
    expect($oa)->not->toBeNull()
        ->and($oa->kind)->toBe('developer')
        ->and($oa->mfa_method)->toBe('passkey')
        ->and($oa->importance_tier)->toBe('high')
        ->and($oa->recovery_contact_id)->toBe($spouse->id)
        ->and((bool) $oa->in_case_of_pack)->toBeTrue();
});

it('filters by in-case-of pack flag', function () {
    authedInHousehold();

    OnlineAccount::create(['service_name' => 'InPack', 'kind' => 'email', 'in_case_of_pack' => true]);
    OnlineAccount::create(['service_name' => 'OutPack', 'kind' => 'forum', 'in_case_of_pack' => false]);

    Livewire::test('online-accounts-index')
        ->set('inCaseOfOnly', true)
        ->assertSee('InPack')
        ->assertDontSee('OutPack');
});

it('scopes online accounts to the current user (and household-joint with null user_id)', function () {
    $me = authedInHousehold();

    $otherUser = User::create([
        'name' => 'Spouse',
        'email' => 'spouse@example.com',
        'password' => bcrypt('secret-1234'),
        'default_household_id' => $me->default_household_id,
    ]);
    Household::find($me->default_household_id)
        ->users()->attach($otherUser->id, ['role' => 'member', 'joined_at' => now()]);

    OnlineAccount::create(['service_name' => 'My Gmail', 'kind' => 'email', 'user_id' => $me->id]);
    OnlineAccount::create(['service_name' => 'Spouse Gmail', 'kind' => 'email', 'user_id' => $otherUser->id]);
    OnlineAccount::create(['service_name' => 'Joint Netflix', 'kind' => 'streaming', 'user_id' => null]);

    $this->get('/online-accounts')
        ->assertSee('My Gmail')
        ->assertSee('Joint Netflix')
        ->assertDontSee('Spouse Gmail');
});

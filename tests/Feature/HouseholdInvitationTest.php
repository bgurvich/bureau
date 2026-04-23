<?php

declare(strict_types=1);

use App\Mail\HouseholdInvitationMail;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use App\Support\CurrentHousehold;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

function seedHouseholdWithOwner(?string $ownerEmail = 'owner@secretaire.local'): array
{
    $household = Household::create(['name' => 'Test', 'default_currency' => 'USD']);
    $owner = User::create([
        'name' => 'Owner',
        'email' => $ownerEmail,
        'password' => bcrypt('secret-1234'),
        'default_household_id' => $household->id,
    ]);
    $household->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);

    return [$household, $owner];
}

it('issues an invitation and stores only the token hash', function () {
    [$household, $owner] = seedHouseholdWithOwner();

    [$invite, $plain] = HouseholdInvitation::issue($household, 'friend@example.com', 'member', $owner);

    expect($plain)->toBeString()->and(strlen($plain))->toBeGreaterThanOrEqual(40)
        ->and($invite->token_hash)->toBe(hash('sha256', $plain))
        ->and($invite->email)->toBe('friend@example.com')
        ->and($invite->role)->toBe('member')
        ->and($invite->invited_by_user_id)->toBe($owner->id)
        ->and($invite->expires_at->isFuture())->toBeTrue();
});

it('sends an invite email from the settings page and rejects non-owners', function () {
    Mail::fake();
    [$household, $owner] = seedHouseholdWithOwner();
    $this->actingAs($owner);
    CurrentHousehold::set($household);

    Livewire::test('settings-index')
        ->set('inviteEmail', 'friend@example.com')
        ->set('inviteRole', 'member')
        ->call('sendInvite')
        ->assertHasNoErrors();

    Mail::assertSent(HouseholdInvitationMail::class, fn ($m) => $m->inviteeEmail === 'friend@example.com');
    expect(HouseholdInvitation::where('email', 'friend@example.com')->exists())->toBeTrue();

    // Non-owner attempt is blocked with a user-visible error.
    $member = User::create([
        'name' => 'Member', 'email' => 'm@example.com',
        'password' => bcrypt('secret-1234'),
        'default_household_id' => $household->id,
    ]);
    $household->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);
    $this->actingAs($member);

    Mail::fake();
    Livewire::test('settings-index')
        ->set('inviteEmail', 'other@example.com')
        ->call('sendInvite');

    Mail::assertNothingSent();
    expect(HouseholdInvitation::where('email', 'other@example.com')->exists())->toBeFalse();
});

it('refuses to invite an email that is already a member', function () {
    Mail::fake();
    [$household, $owner] = seedHouseholdWithOwner();
    $this->actingAs($owner);
    CurrentHousehold::set($household);

    Livewire::test('settings-index')
        ->set('inviteEmail', $owner->email)
        ->call('sendInvite');

    Mail::assertNothingSent();
});

it('accepts an invitation, creates the new user, attaches them, marks the invite used', function () {
    [$household, $owner] = seedHouseholdWithOwner();
    [$invite, $plain] = HouseholdInvitation::issue($household, 'new@example.com', 'member', $owner);

    Livewire::test('accept-invitation', ['token' => $plain])
        ->set('name', 'New User')
        ->set('password', 'strong-pass-123')
        ->set('password_confirmation', 'strong-pass-123')
        ->call('accept')
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'new@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->default_household_id)->toBe($household->id)
        ->and($household->fresh()->users()->where('users.id', $user->id)->exists())->toBeTrue()
        ->and($invite->fresh()->accepted_at)->not->toBeNull()
        ->and($invite->fresh()->accepted_user_id)->toBe($user->id);
});

it('accepts an invitation for an existing account when the password matches', function () {
    [$household, $owner] = seedHouseholdWithOwner();

    $existing = User::create([
        'name' => 'Already Here',
        'email' => 'exists@example.com',
        'password' => bcrypt('known-pass-456'),
    ]);

    [$invite, $plain] = HouseholdInvitation::issue($household, 'exists@example.com', 'member', $owner);

    Livewire::test('accept-invitation', ['token' => $plain])
        ->set('password', 'known-pass-456')
        ->call('accept')
        ->assertRedirect(route('dashboard'));

    expect($household->fresh()->users()->where('users.id', $existing->id)->exists())->toBeTrue()
        ->and($invite->fresh()->accepted_user_id)->toBe($existing->id);
});

it('rejects an accept attempt for an existing account with the wrong password', function () {
    [$household, $owner] = seedHouseholdWithOwner();

    User::create([
        'name' => 'Existing',
        'email' => 'exists@example.com',
        'password' => bcrypt('real-password'),
    ]);
    [$invite, $plain] = HouseholdInvitation::issue($household, 'exists@example.com', 'member', $owner);

    Livewire::test('accept-invitation', ['token' => $plain])
        ->set('password', 'wrong-password')
        ->call('accept')
        ->assertHasErrors(['password']);

    expect($invite->fresh()->accepted_at)->toBeNull();
});

it('rejects expired invitations', function () {
    [$household, $owner] = seedHouseholdWithOwner();
    [$invite, $plain] = HouseholdInvitation::issue($household, 'late@example.com', 'member', $owner);
    $invite->forceFill(['expires_at' => now()->subDay()])->save();

    Livewire::test('accept-invitation', ['token' => $plain])
        ->assertSet('error', __('This invitation has expired. Ask the sender to issue a new one.'));
});

it('rejects already-used invitations', function () {
    [$household, $owner] = seedHouseholdWithOwner();
    [$invite, $plain] = HouseholdInvitation::issue($household, 'reuser@example.com', 'member', $owner);
    $invite->forceFill(['accepted_at' => now()])->save();

    Livewire::test('accept-invitation', ['token' => $plain])
        ->assertSet('error', __('This invitation has already been used. Sign in with your account instead.'));
});

it('revokes a pending invitation', function () {
    [$household, $owner] = seedHouseholdWithOwner();
    [$invite] = HouseholdInvitation::issue($household, 'drop@example.com', 'member', $owner);
    $this->actingAs($owner);
    CurrentHousehold::set($household);

    Livewire::test('settings-index')
        ->call('revokeInvite', $invite->id);

    expect(HouseholdInvitation::find($invite->id))->toBeNull();
});

it('blocks removal of the last owner', function () {
    [$household, $owner] = seedHouseholdWithOwner();
    $this->actingAs($owner);
    CurrentHousehold::set($household);

    Livewire::test('settings-index')
        ->call('removeMember', $owner->id);

    // Self-removal of last owner is silently blocked (UI hides the button anyway).
    expect($household->fresh()->users()->where('users.id', $owner->id)->exists())->toBeTrue();

    // Second owner present → one can be removed, leaving the other as sole owner.
    $other = User::create([
        'name' => 'Other', 'email' => 'other@secretaire.local',
        'password' => bcrypt('secret-1234'),
        'default_household_id' => $household->id,
    ]);
    $household->users()->attach($other->id, ['role' => 'owner', 'joined_at' => now()]);

    Livewire::test('settings-index')
        ->call('removeMember', $other->id);

    expect($household->fresh()->users()->where('users.id', $other->id)->exists())->toBeFalse();
});

it('rotates the token on resend so the previous URL stops working', function () {
    Mail::fake();
    [$household, $owner] = seedHouseholdWithOwner();
    [$invite, $originalPlain] = HouseholdInvitation::issue($household, 'resend@example.com', 'member', $owner);
    $this->actingAs($owner);
    CurrentHousehold::set($household);

    Livewire::test('settings-index')
        ->call('resendInvite', $invite->id);

    $fresh = $invite->fresh();
    expect($fresh->token_hash)->not->toBe(hash('sha256', $originalPlain));
    Mail::assertSent(HouseholdInvitationMail::class);
});

afterEach(function () {
    CurrentHousehold::set(null);
});

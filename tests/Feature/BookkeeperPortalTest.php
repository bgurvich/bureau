<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\PortalGrant;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('issues a grant and returns the one-time raw token once', function () {
    $user = authedInHousehold();

    [$grant, $raw] = PortalGrant::issue(
        householdId: (int) $user->defaultHousehold->id,
        expiresAt: CarbonImmutable::now()->addDays(30),
        granteeEmail: 'cpa@firm.com',
        label: '2025 Tax',
    );

    expect(strlen($raw))->toBe(PortalGrant::TOKEN_LENGTH)
        ->and($grant->token_hash)->toBe(hash('sha256', $raw))
        ->and($grant->token_hash)->not->toBe($raw) // never store raw
        ->and($grant->token_tail)->toBe(substr($raw, -6))
        ->and($grant->scope)->toBe(['fiscal']);
});

it('findByToken rejects a miss, a revoked, and an expired grant', function () {
    $user = authedInHousehold();

    [$good, $rawGood] = PortalGrant::issue(
        householdId: (int) $user->defaultHousehold->id,
        expiresAt: CarbonImmutable::now()->addDays(30),
    );

    expect(PortalGrant::findByToken($rawGood)?->id)->toBe($good->id);

    // Miss
    expect(PortalGrant::findByToken(str_repeat('0', PortalGrant::TOKEN_LENGTH)))->toBeNull();

    // Revoked
    $good->revoke();
    expect(PortalGrant::findByToken($rawGood))->toBeNull();

    // Expired
    [, $rawExpired] = PortalGrant::issue(
        householdId: (int) $user->defaultHousehold->id,
        expiresAt: CarbonImmutable::now()->subDay(),
    );
    expect(PortalGrant::findByToken($rawExpired))->toBeNull();
});

it('portal consume route logs into a scoped session and redirects to dashboard', function () {
    $user = authedInHousehold();
    auth()->logout(); // portal is guest-style

    [, $raw] = PortalGrant::issue(
        householdId: (int) $user->defaultHousehold->id,
        expiresAt: CarbonImmutable::now()->addDays(7),
    );

    $response = $this->get(route('portal.consume', ['token' => $raw]));
    $response->assertRedirect(route('portal.dashboard'));

    expect(session('portal_grant_id'))->not->toBeNull();
});

it('portal consume route redirects to expired for a bad token', function () {
    authedInHousehold();
    auth()->logout();

    $response = $this->get(route('portal.consume', ['token' => str_repeat('0', PortalGrant::TOKEN_LENGTH)]));
    $response->assertRedirect(route('portal.expired'));
});

it('portal dashboard requires a valid portal session', function () {
    authedInHousehold();
    auth()->logout();

    $this->get(route('portal.dashboard'))->assertRedirect(route('portal.expired'));
});

it('portal dashboard lists transactions for the grant\'s household (scope isolation)', function () {
    $userA = authedInHousehold('A', 'a@example.com');
    $accountA = Account::create(['type' => 'checking', 'name' => 'A checking', 'currency' => 'USD', 'opening_balance' => 0]);
    Transaction::create([
        'account_id' => $accountA->id, 'occurred_on' => '2026-04-10',
        'amount' => -10, 'currency' => 'USD', 'description' => 'A txn',
    ]);

    [, $rawA] = PortalGrant::issue(
        householdId: (int) $userA->defaultHousehold->id,
        expiresAt: CarbonImmutable::now()->addDays(30),
    );

    // Second household with its own transaction.
    $userB = authedInHousehold('B', 'b@example.com');
    $accountB = Account::create(['type' => 'checking', 'name' => 'B checking', 'currency' => 'USD', 'opening_balance' => 0]);
    Transaction::create([
        'account_id' => $accountB->id, 'occurred_on' => '2026-04-12',
        'amount' => -20, 'currency' => 'USD', 'description' => 'B txn',
    ]);

    auth()->logout();
    $this->get(route('portal.consume', ['token' => $rawA])); // sets session to A's grant
    $this->get(route('portal.dashboard'))
        ->assertOk()
        ->assertSee('A txn')
        ->assertDontSee('B txn');
});

it('portal logout clears the session', function () {
    $user = authedInHousehold();
    auth()->logout();

    [, $raw] = PortalGrant::issue(
        householdId: (int) $user->defaultHousehold->id,
        expiresAt: CarbonImmutable::now()->addDays(7),
    );

    $this->get(route('portal.consume', ['token' => $raw]));
    expect(session('portal_grant_id'))->not->toBeNull();

    $this->post(route('portal.logout'));
    expect(session('portal_grant_id'))->toBeNull();
});

it('portal-grants-manager issues a grant and exposes the one-time URL', function () {
    authedInHousehold();

    $c = Livewire::test('portal-grants-manager')
        ->set('grantee_email', 'cpa@firm.com')
        ->set('label', 'Q4 close')
        ->set('expires_in_days', 30)
        ->call('issue');

    expect($c->get('oneTimeUrl'))->toContain('/portal/')
        ->and(PortalGrant::count())->toBe(1)
        ->and(PortalGrant::first()->grantee_email)->toBe('cpa@firm.com');
});

it('portal-grants-manager revoke flips a grant revoked_at', function () {
    $user = authedInHousehold();
    [$grant] = PortalGrant::issue(
        householdId: (int) $user->defaultHousehold->id,
        expiresAt: CarbonImmutable::now()->addDays(30),
    );

    expect($grant->revoked_at)->toBeNull();

    Livewire::test('portal-grants-manager')->call('revoke', $grant->id);

    expect($grant->fresh()->revoked_at)->not->toBeNull();
});

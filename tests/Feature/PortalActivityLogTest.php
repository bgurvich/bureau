<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\PortalActivityEvent;
use App\Models\PortalGrant;
use App\Models\Transaction;
use App\Support\CurrentHousehold;
use App\Support\PortalActivityLog;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('logs consumed_token on successful portal consume', function () {
    $user = authedInHousehold();
    auth()->logout();

    [, $raw] = PortalGrant::issue(
        householdId: (int) $user->defaultHousehold->id,
        expiresAt: CarbonImmutable::now()->addDays(7),
    );

    $this->get('/portal/'.$raw)->assertRedirect(route('portal.dashboard'));

    $event = PortalActivityEvent::query()
        ->where('action', 'consumed_token')
        ->withoutGlobalScope('household')
        ->firstOrFail();

    expect($event->portal_grant_id)->not->toBeNull()
        ->and($event->household_id)->toBe($user->defaultHousehold->id)
        ->and($event->metadata['ip'] ?? null)->not->toBeNull();
});

it('logs page_view when portal dashboard is hit', function () {
    $user = authedInHousehold();
    auth()->logout();

    [, $raw] = PortalGrant::issue(
        householdId: (int) $user->defaultHousehold->id,
        expiresAt: CarbonImmutable::now()->addDays(7),
    );

    // Consume first to set up the session
    $this->get('/portal/'.$raw);
    // Then hit the dashboard
    $this->get(route('portal.dashboard'))->assertOk();

    expect(PortalActivityEvent::where('action', 'page_view')->withoutGlobalScope('household')->count())
        ->toBeGreaterThanOrEqual(1);
});

it('logs export_csv on portal export download', function () {
    $user = authedInHousehold();
    CurrentHousehold::set($user->defaultHousehold);
    $account = Account::create([
        'type' => 'checking', 'name' => 'Test', 'currency' => 'USD', 'opening_balance' => 0,
    ]);
    auth()->logout();

    [, $raw] = PortalGrant::issue(
        householdId: (int) $user->defaultHousehold->id,
        expiresAt: CarbonImmutable::now()->addDays(7),
        label: 'Q1-2026',
    );

    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-01-15',
        'amount' => -12.50,
        'currency' => 'USD',
        'description' => 'Test',
        'status' => 'cleared',
    ]);

    $this->get('/portal/'.$raw); // consume

    $response = $this->get(route('portal.export', ['from' => '2026-01-01', 'to' => '2026-01-31']));
    $response->assertOk();

    $event = PortalActivityEvent::query()
        ->where('action', 'export_csv')
        ->withoutGlobalScope('household')
        ->firstOrFail();

    expect($event->metadata['filename'] ?? '')->toContain('q1-2026')
        ->and($event->metadata['from'] ?? null)->toBe('2026-01-01')
        ->and($event->metadata['to'] ?? null)->toBe('2026-01-31');
});

it('logs signed_out when the portal logout route fires', function () {
    $user = authedInHousehold();
    auth()->logout();

    [, $raw] = PortalGrant::issue(
        householdId: (int) $user->defaultHousehold->id,
        expiresAt: CarbonImmutable::now()->addDays(7),
    );

    $this->get('/portal/'.$raw);
    $this->post(route('portal.logout'))->assertRedirect(route('portal.expired'));

    expect(PortalActivityEvent::where('action', 'signed_out')->withoutGlobalScope('household')->count())->toBe(1);
});

it('owner sees a recent-activity section on the portal-grants manager', function () {
    $user = authedInHousehold();

    [$grant] = PortalGrant::issue(
        householdId: (int) $user->defaultHousehold->id,
        expiresAt: CarbonImmutable::now()->addDays(7),
        label: 'Audit trail demo',
    );

    PortalActivityLog::record('page_view', $grant, request(), ['route' => 'portal.dashboard']);

    $c = Livewire::test('portal-grants-manager');
    expect($c->get('activity')->count())->toBeGreaterThanOrEqual(1);
});

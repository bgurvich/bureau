<?php

declare(strict_types=1);

use App\Jobs\PayPalBackfillJob;
use App\Models\Account;
use App\Models\Integration;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function seedPayPalIntegration(): Integration
{
    $account = Account::create([
        'type' => 'checking', 'name' => 'PayPal',
        'currency' => 'USD', 'opening_balance' => 0,
    ]);

    return Integration::forceCreate([
        'provider' => 'paypal',
        'kind' => 'bank',
        'label' => 'Household PayPal',
        'credentials' => ['client_id' => 'x', 'client_secret' => 'y'],
        'settings' => ['account_id' => $account->id, 'webhook_id' => 'WH-1'],
        'status' => 'active',
    ]);
}

it('opens the backfill modal with a 3-year default start date', function () {
    authedInHousehold();
    $int = seedPayPalIntegration();

    Livewire::test('settings-index')
        ->call('openBackfill', $int->id)
        ->assertSet('backfillIntegrationId', $int->id)
        ->assertSet('backfillFrom', now()->subYears(3)->toDateString());
});

it('queues PayPalBackfillJob when startBackfill is invoked with a valid date', function () {
    authedInHousehold();
    Queue::fake();
    $int = seedPayPalIntegration();

    Livewire::test('settings-index')
        ->call('openBackfill', $int->id)
        ->set('backfillFrom', '2023-06-01')
        ->call('startBackfill')
        ->assertSet('backfillIntegrationId', null);

    Queue::assertPushed(PayPalBackfillJob::class, fn ($j) => $j->integrationId === $int->id
        && $j->fromDate === '2023-06-01');
});

it('refuses a future backfill start date', function () {
    authedInHousehold();
    Queue::fake();
    $int = seedPayPalIntegration();

    Livewire::test('settings-index')
        ->call('openBackfill', $int->id)
        ->set('backfillFrom', now()->addYear()->toDateString())
        ->call('startBackfill')
        // Modal stays open, no job queued.
        ->assertSet('backfillIntegrationId', $int->id);

    Queue::assertNothingPushed();
});

it('refuses a malformed backfill date', function () {
    authedInHousehold();
    Queue::fake();
    $int = seedPayPalIntegration();

    Livewire::test('settings-index')
        ->call('openBackfill', $int->id)
        ->set('backfillFrom', 'not-a-date')
        ->call('startBackfill')
        ->assertSet('backfillIntegrationId', $int->id);

    Queue::assertNothingPushed();
});

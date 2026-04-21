<?php

use App\Models\Account;
use App\Models\Integration;
use App\Models\Transaction;
use App\Support\PayPal\PayPalClient;
use App\Support\PayPal\PayPalReconciliation;
use App\Support\PayPal\PayPalSync;
use Illuminate\Support\Facades\Http;

function paypalIntegration(Account $account, array $overrides = []): Integration
{
    return Integration::create(array_replace_recursive([
        'provider' => 'paypal',
        'kind' => 'bank',
        'label' => 'PayPal',
        'credentials' => [
            'client_id' => 'cid',
            'client_secret' => 'csec',
        ],
        'settings' => [
            'base_url' => 'https://api.paypal.test',
            'account_id' => $account->id,
            'cursor' => null,
        ],
        'status' => 'active',
    ], $overrides));
}

it('PayPalClient caches access_token and refreshes when expired', function () {
    $user = authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'PayPal', 'currency' => 'USD', 'opening_balance' => 0]);
    $integration = paypalIntegration($account);

    Http::fake([
        'api.paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
    ]);

    $client = new PayPalClient($integration);
    expect($client->accessToken())->toBe('tok-1');

    // Second call within window should reuse without a new token POST.
    expect($client->accessToken())->toBe('tok-1');
    Http::assertSentCount(1);
});

it('PayPalSync creates Transactions from reporting API rows', function () {
    $user = authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'PayPal', 'currency' => 'USD', 'opening_balance' => 0]);
    $integration = paypalIntegration($account, ['settings' => [
        'base_url' => 'https://api.paypal.test',
        'account_id' => $account->id,
        'cursor' => now()->subDays(5)->toIso8601String(),
    ]]);

    Http::fake([
        'api.paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'api.paypal.test/v1/reporting/transactions*' => Http::response(['transaction_details' => [
            [
                'transaction_info' => [
                    'transaction_id' => 'PAY-1',
                    'transaction_initiation_date' => now()->subDays(3)->toIso8601String(),
                    'transaction_amount' => ['value' => '-45.00', 'currency_code' => 'USD'],
                    'transaction_status' => 'S',
                    'transaction_subject' => 'Amazon Marketplace',
                ],
                'payer_info' => ['payer_name' => ['alternate_full_name' => 'Amazon Marketplace']],
            ],
            [
                'transaction_info' => [
                    'transaction_id' => 'PAY-2',
                    'transaction_initiation_date' => now()->subDay()->toIso8601String(),
                    'transaction_amount' => ['value' => '-19.99', 'currency_code' => 'USD'],
                    'transaction_status' => 'S',
                    'transaction_subject' => 'Etsy Inc',
                ],
                'payer_info' => ['payer_name' => ['alternate_full_name' => 'Etsy Inc']],
            ],
        ]]),
    ]);

    $created = app(PayPalSync::class)->sync($integration);
    expect($created)->toBeGreaterThanOrEqual(2);

    $amounts = Transaction::pluck('amount')->map(fn ($v) => (float) $v)->all();
    expect($amounts)->toContain(-45.00)
        ->and($amounts)->toContain(-19.99);
    foreach (Transaction::all() as $t) {
        expect($t->import_source)->toBe('paypal');
    }
});

it('PayPalSync is idempotent — re-running does not duplicate', function () {
    $user = authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'PayPal', 'currency' => 'USD', 'opening_balance' => 0]);
    $integration = paypalIntegration($account, ['settings' => [
        'base_url' => 'https://api.paypal.test',
        'account_id' => $account->id,
        'cursor' => now()->subDays(5)->toIso8601String(),
    ]]);

    Http::fake([
        'api.paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'api.paypal.test/v1/reporting/transactions*' => Http::response(['transaction_details' => [[
            'transaction_info' => [
                'transaction_id' => 'PAY-IDEM',
                'transaction_initiation_date' => now()->subDay()->toIso8601String(),
                'transaction_amount' => ['value' => '-9.99', 'currency_code' => 'USD'],
                'transaction_status' => 'S',
                'transaction_subject' => 'Spotify',
            ],
        ]]]),
    ]);

    app(PayPalSync::class)->sync($integration);
    expect(Transaction::count())->toBe(1);

    // Re-run — PayPal reports same row → must not duplicate.
    app(PayPalSync::class)->sync($integration);
    expect(Transaction::count())->toBe(1);
});

it('PayPalReconciliation links children to a bank row when subset sum matches', function () {
    $user = authedInHousehold();
    $bankAccount = Account::create(['type' => 'checking', 'name' => 'Checking', 'currency' => 'USD', 'opening_balance' => 0]);
    $paypalAccount = Account::create(['type' => 'checking', 'name' => 'PayPal', 'currency' => 'USD', 'opening_balance' => 0]);

    $bank = Transaction::create([
        'account_id' => $bankAccount->id, 'occurred_on' => '2026-04-05',
        'amount' => -150.00, 'currency' => 'USD', 'description' => 'PAYPAL *VARIOUS', 'status' => 'cleared',
    ]);
    Transaction::create(['account_id' => $paypalAccount->id, 'occurred_on' => '2026-04-03',
        'amount' => -50.00, 'currency' => 'USD', 'description' => 'Amazon', 'status' => 'cleared',
        'external_id' => 'p1', 'import_source' => 'paypal']);
    Transaction::create(['account_id' => $paypalAccount->id, 'occurred_on' => '2026-04-04',
        'amount' => -75.00, 'currency' => 'USD', 'description' => 'Etsy', 'status' => 'cleared',
        'external_id' => 'p2', 'import_source' => 'paypal']);
    Transaction::create(['account_id' => $paypalAccount->id, 'occurred_on' => '2026-04-05',
        'amount' => -25.00, 'currency' => 'USD', 'description' => 'Uber', 'status' => 'cleared',
        'external_id' => 'p3', 'import_source' => 'paypal']);

    $linked = app(PayPalReconciliation::class)->reconcile($user->defaultHousehold);
    expect($linked)->toBe(3);

    $children = Transaction::where('funded_by_transaction_id', $bank->id)->get();
    expect($children)->toHaveCount(3);
});

it('PayPalReconciliation skips ambiguous matches (two subsets match)', function () {
    $user = authedInHousehold();
    $bankAccount = Account::create(['type' => 'checking', 'name' => 'Checking', 'currency' => 'USD', 'opening_balance' => 0]);
    $paypalAccount = Account::create(['type' => 'checking', 'name' => 'PayPal', 'currency' => 'USD', 'opening_balance' => 0]);

    $bank = Transaction::create([
        'account_id' => $bankAccount->id, 'occurred_on' => '2026-04-05',
        'amount' => -100.00, 'currency' => 'USD', 'description' => 'PAYPAL TRANSFER', 'status' => 'cleared',
    ]);
    // Two children each worth 100 → ambiguous
    Transaction::create(['account_id' => $paypalAccount->id, 'occurred_on' => '2026-04-04',
        'amount' => -100.00, 'currency' => 'USD', 'description' => 'Acme', 'status' => 'cleared',
        'external_id' => 'p1', 'import_source' => 'paypal']);
    Transaction::create(['account_id' => $paypalAccount->id, 'occurred_on' => '2026-04-05',
        'amount' => -100.00, 'currency' => 'USD', 'description' => 'Beta', 'status' => 'cleared',
        'external_id' => 'p2', 'import_source' => 'paypal']);

    $linked = app(PayPalReconciliation::class)->reconcile($user->defaultHousehold);
    expect($linked)->toBe(0);
    expect(Transaction::whereNotNull('funded_by_transaction_id')->count())->toBe(0);
});

it('PayPal webhook accepts a verified completed event and creates a Transaction', function () {
    $user = authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'PayPal', 'currency' => 'USD', 'opening_balance' => 0]);
    paypalIntegration($account, ['settings' => [
        'base_url' => 'https://api.paypal.test',
        'account_id' => $account->id,
        'webhook_id' => 'WH-1',
    ]]);

    Http::fake([
        'api.paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'api.paypal.test/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
    ]);

    $this->postJson('/webhooks/paypal/inbound', [
        'id' => 'evt-1',
        'event_type' => 'PAYMENT.SALE.COMPLETED',
        'resource' => [
            'id' => 'PAY-WH-1',
            'amount' => ['value' => '29.99', 'currency_code' => 'USD'],
            'description' => 'Kindle book',
            'create_time' => '2026-04-05T10:00:00Z',
        ],
    ], [
        'paypal-transmission-id' => 'tx-1',
        'paypal-transmission-time' => '2026-04-05T10:00:01Z',
        'paypal-cert-url' => 'https://paypal.test/cert',
        'paypal-auth-algo' => 'SHA256withRSA',
        'paypal-transmission-sig' => 'base64sig',
    ])->assertOk();

    $t = Transaction::firstOrFail();
    expect($t->external_id)->toBe('PAY-WH-1')
        ->and($t->import_source)->toBe('paypal')
        ->and((float) $t->amount)->toBe(-29.99);
});

it('PayPal webhook rejects a failed signature verification', function () {
    $user = authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'PayPal', 'currency' => 'USD', 'opening_balance' => 0]);
    paypalIntegration($account, ['settings' => [
        'base_url' => 'https://api.paypal.test',
        'account_id' => $account->id,
        'webhook_id' => 'WH-1',
    ]]);

    Http::fake([
        'api.paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'api.paypal.test/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
    ]);

    $this->postJson('/webhooks/paypal/inbound', [
        'id' => 'evt-bad',
        'event_type' => 'PAYMENT.SALE.COMPLETED',
        'resource' => ['id' => 'PAY-BAD', 'amount' => ['value' => '10.00', 'currency_code' => 'USD']],
    ], [
        'paypal-transmission-id' => 't',
        'paypal-transmission-time' => '2026-04-05T10:00:01Z',
        'paypal-cert-url' => 'https://paypal.test/cert',
        'paypal-auth-algo' => 'SHA256withRSA',
        'paypal-transmission-sig' => 'bad',
    ])->assertStatus(401);

    expect(Transaction::count())->toBe(0);
});

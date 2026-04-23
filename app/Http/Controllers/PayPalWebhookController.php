<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Integration;
use App\Models\Transaction;
use App\Support\CurrentHousehold;
use App\Support\PayPal\PayPalClient;
use App\Support\ProjectionMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives PayPal webhook events for realtime transaction updates. PayPal
 * signs each POST with headers that feed back into its own verification
 * endpoint (the service doesn't publish raw JWKs we could validate
 * locally, so we delegate). The webhook_id used for verification is stored
 * on the Integration row at registration time.
 *
 * Event types we react to:
 *   PAYMENT.SALE.COMPLETED, PAYMENT.CAPTURE.COMPLETED, CHECKOUT.ORDER.COMPLETED
 *   → upsert a Transaction keyed by resource.id.
 * Everything else is acknowledged-200 so PayPal stops retrying.
 */
final class PayPalWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();
        $eventId = (string) ($payload['id'] ?? '');
        $eventType = (string) ($payload['event_type'] ?? '');

        $integration = $this->resolveIntegration($request, $payload);
        if (! $integration) {
            Log::warning('PayPal webhook — no matching integration', ['event_id' => $eventId]);

            return response()->json(['ok' => false, 'reason' => 'no integration'], 404);
        }

        $verified = $this->verifySignature($request, $integration, $payload);
        if (! $verified) {
            Log::warning('PayPal webhook signature verification failed', ['event_id' => $eventId]);

            return response()->json(['ok' => false, 'reason' => 'bad signature'], 401);
        }

        if (! $integration->household) {
            return response()->json(['ok' => false, 'reason' => 'orphan integration'], 500);
        }
        CurrentHousehold::set($integration->household);

        $created = $this->upsertFromEvent($integration, $payload, $eventType);

        return response()->json(['ok' => true, 'event_type' => $eventType, 'created' => $created]);
    }

    /**
     * Matches the webhook to a PayPal Integration either by (a) webhook_id
     * header if PayPal includes it (PAYPAL-TRANSMISSION-ID is per-event, not
     * webhook-level), or (b) settings.webhook_id registered at setup time.
     * For v1: accept any active PayPal integration in the household with a
     * stored webhook_id matching the one we registered — if exactly one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveIntegration(Request $request, array $payload): ?Integration
    {
        // If the request URL carries the integration id as a path/query
        // param (user set this up themselves), honor it. Otherwise, fall
        // back to "the only active PayPal integration" and let signature
        // verification gate correctness.
        $forcedId = (int) $request->query('integration', 0);
        if ($forcedId > 0) {
            return Integration::withoutGlobalScopes()->where('provider', 'paypal')->find($forcedId);
        }

        return Integration::withoutGlobalScopes()
            ->where('provider', 'paypal')
            ->where('status', 'active')
            ->whereNotNull('credentials')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function verifySignature(Request $request, Integration $integration, array $payload): bool
    {
        $settings = (array) ($integration->settings ?? []);
        $webhookId = (string) ($settings['webhook_id'] ?? '');
        if ($webhookId === '') {
            // No webhook_id registered — only allow the honor-system bypass
            // in local/testing. In any other environment an absent webhook_id
            // is a configuration error, not a reason to trust anonymous POSTs.
            if (! app()->environment(['local', 'testing'])) {
                return false;
            }

            return config('services.paypal.verify_webhook_signature', true) === false;
        }

        $client = new PayPalClient($integration);
        $http = $client->authed();
        if ($http === null) {
            return false;
        }

        try {
            $response = $http->post($client->baseUrl().'/v1/notifications/verify-webhook-signature', [
                'transmission_id' => $request->header('paypal-transmission-id'),
                'transmission_time' => $request->header('paypal-transmission-time'),
                'cert_url' => $request->header('paypal-cert-url'),
                'auth_algo' => $request->header('paypal-auth-algo'),
                'transmission_sig' => $request->header('paypal-transmission-sig'),
                'webhook_id' => $webhookId,
                'webhook_event' => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PayPal signature verify request failed', ['error' => $e->getMessage()]);

            return false;
        }

        return $response->successful()
            && strtoupper((string) $response->json('verification_status')) === 'SUCCESS';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertFromEvent(Integration $integration, array $payload, string $eventType): bool
    {
        if (! preg_match('/COMPLETED|CAPTURED|REFUNDED/i', $eventType)) {
            return false;
        }

        $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
        $providerId = (string) ($resource['id'] ?? '');
        if ($providerId === '') {
            return false;
        }

        $settings = (array) ($integration->settings ?? []);
        $accountId = (int) ($settings['account_id'] ?? 0);
        if ($accountId === 0 || ! Account::find($accountId)) {
            return false;
        }

        if (Transaction::where('account_id', $accountId)->where('external_id', $providerId)->exists()) {
            return false;
        }

        $amountInfo = is_array($resource['amount'] ?? null) ? $resource['amount'] : [];
        $valueRaw = $amountInfo['value'] ?? ($resource['gross_amount']['value'] ?? null);
        $currency = (string) ($amountInfo['currency_code'] ?? ($resource['gross_amount']['currency_code'] ?? 'USD'));
        if (! is_numeric($valueRaw)) {
            return false;
        }
        $amount = (float) $valueRaw;
        if (preg_match('/REFUNDED/i', $eventType)) {
            $amount = abs($amount);
        } elseif ($amount > 0) {
            // For outgoing payments, PayPal sends positive values — Secretaire convention negates them.
            $amount = -$amount;
        }

        $description = (string) (
            $resource['payee']['email_address']
                ?? $resource['description']
                ?? $resource['transaction_subject']
                ?? 'PayPal '.$providerId
        );

        $occurredOn = is_string($resource['create_time'] ?? null)
            ? CarbonImmutable::parse($resource['create_time'])->toDateString()
            : CarbonImmutable::now()->toDateString();

        try {
            $txn = Transaction::create([
                'account_id' => $accountId,
                'occurred_on' => $occurredOn,
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'status' => 'cleared',
                'external_id' => $providerId,
                'import_source' => 'paypal',
            ]);
        } catch (QueryException) {
            return false;
        }
        ProjectionMatcher::attempt($txn);

        return true;
    }
}

<?php

namespace App\Support\PayPal;

use App\Models\Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin server-to-server client for PayPal's REST API.
 *
 * PayPal uses client-credentials OAuth (not user-authorization) for server
 * integrations — POST `/v1/oauth2/token` with basic auth (client_id:secret)
 * returns a short-lived access_token. No user redirect, no refresh token.
 *
 * Per-integration config:
 *   credentials.client_id      — from PayPal developer dashboard
 *   credentials.client_secret  — ditto
 *   credentials.access_token   — cached
 *   credentials.access_token_expires_at — unix timestamp
 *   settings.base_url          — "https://api-m.sandbox.paypal.com" or live
 *   settings.account_id        — Bureau Account id the transactions land on
 *   settings.cursor            — ISO timestamp of last sync
 *   settings.webhook_id        — PayPal-side webhook id for sig verify
 */
class PayPalClient
{
    public function __construct(public readonly Integration $integration) {}

    public function baseUrl(): string
    {
        $url = (string) (($this->integration->settings ?? [])['base_url'] ?? 'https://api-m.paypal.com');

        return rtrim($url, '/');
    }

    public function authed(): ?PendingRequest
    {
        $access = $this->accessToken();
        if ($access === null) {
            return null;
        }

        return Http::withToken($access)->acceptJson();
    }

    public function accessToken(): ?string
    {
        $creds = (array) $this->integration->credentials;
        $expires = (int) ($creds['access_token_expires_at'] ?? 0);
        $token = (string) ($creds['access_token'] ?? '');
        if ($token !== '' && $expires > time() + 30) {
            return $token;
        }

        $clientId = (string) ($creds['client_id'] ?? '');
        $clientSecret = (string) ($creds['client_secret'] ?? '');
        if ($clientId === '' || $clientSecret === '') {
            Log::warning('PayPal integration missing client credentials', ['integration_id' => $this->integration->id]);

            return null;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($clientId, $clientSecret)
                ->acceptJson()
                ->post($this->baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        } catch (\Throwable $e) {
            Log::warning('PayPal token fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            Log::warning('PayPal token non-2xx', ['status' => $response->status(), 'body' => $response->body()]);

            return null;
        }

        $tokenFresh = (string) ($response->json('access_token') ?? '');
        $expiresIn = (int) ($response->json('expires_in') ?? 3300);
        if ($tokenFresh === '') {
            return null;
        }

        $creds['access_token'] = $tokenFresh;
        $creds['access_token_expires_at'] = time() + $expiresIn;
        $this->integration->credentials = $creds;
        $this->integration->save();

        return $tokenFresh;
    }
}

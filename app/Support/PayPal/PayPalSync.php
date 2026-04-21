<?php

namespace App\Support\PayPal;

use App\Models\Account;
use App\Models\Integration;
use App\Models\Transaction;
use App\Support\CurrentHousehold;
use App\Support\ProjectionMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Pulls PayPal transaction history into Bureau via the Reporting API:
 * GET /v1/reporting/transactions?start_date=...&end_date=...&fields=all.
 *
 * Each PayPal transaction becomes a Bureau Transaction on the mapped
 * Account (integration.settings.account_id). `external_id` is the PayPal
 * `transaction_id`; `import_source = 'paypal'`; `status` follows the
 * PayPal transaction_status (S=cleared, P=pending). The pivot
 * `integration.settings.cursor` walks forward one API window at a time.
 *
 * Reporting API caps requests to 31 days per call and 1M rows total; we
 * chunk in 30-day windows and stop at "now".
 */
class PayPalSync
{
    private const WINDOW_DAYS = 30;

    public function sync(Integration $integration): int
    {
        $household = $integration->household;
        if (! $household) {
            return 0;
        }
        CurrentHousehold::set($household);

        $client = new PayPalClient($integration);
        if ($client->accessToken() === null) {
            return 0;
        }

        $settings = (array) ($integration->settings ?? []);
        $accountId = (int) ($settings['account_id'] ?? 0);
        if ($accountId === 0 || ! Account::find($accountId)) {
            Log::warning('PayPal integration has no target account', ['integration_id' => $integration->id]);

            return 0;
        }

        $cursor = isset($settings['cursor']) && is_string($settings['cursor']) && $settings['cursor'] !== ''
            ? CarbonImmutable::parse($settings['cursor'])
            : CarbonImmutable::now()->subMonths(3);  // sensible default for new integrations
        $now = CarbonImmutable::now();

        $created = 0;
        $windowStart = $cursor;
        while ($windowStart->lt($now)) {
            $windowEnd = $windowStart->addDays(self::WINDOW_DAYS);
            if ($windowEnd->gt($now)) {
                $windowEnd = $now;
            }
            $created += $this->syncWindow($integration, $accountId, $windowStart, $windowEnd);
            $windowStart = $windowEnd;
        }

        $settings['cursor'] = $now->toIso8601String();
        $integration->settings = $settings;
        $integration->last_synced_at = now();
        $integration->save();

        return $created;
    }

    private function syncWindow(Integration $integration, int $accountId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $client = new PayPalClient($integration);
        $http = $client->authed();
        if ($http === null) {
            return 0;
        }

        $response = $http->get($client->baseUrl().'/v1/reporting/transactions', [
            'start_date' => $from->toIso8601String(),
            'end_date' => $to->toIso8601String(),
            'fields' => 'all',
            'page_size' => 500,
            'page' => 1,
        ]);
        if (! $response->successful()) {
            Log::warning('PayPal reporting API non-2xx', ['status' => $response->status(), 'body' => $response->body()]);

            return 0;
        }

        $created = 0;
        foreach ((array) $response->json('transaction_details', []) as $row) {
            $txn = $this->mapAndPersist($integration, $accountId, is_array($row) ? $row : []);
            if ($txn !== null) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapAndPersist(Integration $integration, int $accountId, array $row): ?Transaction
    {
        $info = is_array($row['transaction_info'] ?? null) ? $row['transaction_info'] : [];
        $providerId = (string) ($info['transaction_id'] ?? '');
        if ($providerId === '') {
            return null;
        }

        // Idempotence via unique(account_id, external_id).
        $existing = Transaction::where('account_id', $accountId)->where('external_id', $providerId)->first();
        if ($existing) {
            return null;
        }

        $amountInfo = is_array($info['transaction_amount'] ?? null) ? $info['transaction_amount'] : [];
        $valueRaw = $amountInfo['value'] ?? null;
        if (! is_numeric($valueRaw)) {
            return null;
        }
        $amount = (float) $valueRaw;  // PayPal returns signed (refunds negative, purchases negative for outflows)
        $currency = (string) ($amountInfo['currency_code'] ?? 'USD');

        $date = is_string($info['transaction_initiation_date'] ?? null)
            ? CarbonImmutable::parse($info['transaction_initiation_date'])
            : CarbonImmutable::now();

        $status = match (strtoupper((string) ($info['transaction_status'] ?? ''))) {
            'S' => 'cleared',
            'P' => 'pending',
            'V' => 'voided',
            'D' => 'failed',
            default => 'cleared',
        };

        $payer = is_array($row['payer_info'] ?? null) ? $row['payer_info'] : [];
        $counterparty = is_string($payer['payer_name']['alternate_full_name'] ?? null)
            ? $payer['payer_name']['alternate_full_name']
            : (string) ($info['transaction_note'] ?? $info['transaction_subject'] ?? '');

        $description = $counterparty !== '' ? $counterparty : ('PayPal '.$providerId);

        try {
            $txn = Transaction::create([
                'account_id' => $accountId,
                'occurred_on' => $date->toDateString(),
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'status' => $status,
                'external_id' => $providerId,
                'import_source' => 'paypal',
            ]);
        } catch (QueryException) {
            return null;
        }

        ProjectionMatcher::attempt($txn);

        return $txn;
    }
}

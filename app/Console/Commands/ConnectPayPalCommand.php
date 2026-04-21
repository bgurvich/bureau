<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Household;
use App\Models\Integration;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class ConnectPayPalCommand extends Command
{
    protected $signature = 'integrations:connect-paypal
        {--household= : Household id to attach this integration to}
        {--client-id= : PayPal REST app client id}
        {--client-secret= : PayPal REST app client secret}
        {--account= : Bureau Account id (the "PayPal" account transactions land on)}
        {--webhook-id= : PayPal webhook id for signature verification (optional)}
        {--sandbox : Use PayPal sandbox base URL}';

    protected $description = 'Provision a PayPal integration — client credentials, target account, webhook id.';

    public function handle(): int
    {
        $household = $this->resolveHousehold();
        if (! $household) {
            return self::FAILURE;
        }

        $clientId = (string) ($this->option('client-id') ?: text(
            label: 'PayPal client_id',
            hint: 'From developer.paypal.com → My Apps & Credentials'
        ));
        $clientSecret = (string) ($this->option('client-secret') ?: password(
            label: 'PayPal client_secret'
        ));
        if ($clientId === '' || $clientSecret === '') {
            $this->error('client_id and client_secret are required.');

            return self::FAILURE;
        }

        $accountId = (int) ($this->option('account') ?: $this->pickAccount($household));
        if ($accountId === 0) {
            $this->error('No target account picked.');

            return self::FAILURE;
        }

        $webhookId = (string) ($this->option('webhook-id') ?: text(
            label: 'PayPal webhook_id (optional, for signature verification)',
            default: ''
        ));

        $baseUrl = $this->option('sandbox') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

        Integration::create([
            'household_id' => $household->id,
            'provider' => 'paypal',
            'kind' => 'bank',
            'label' => 'PayPal',
            'credentials' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ],
            'settings' => [
                'base_url' => $baseUrl,
                'account_id' => $accountId,
                'webhook_id' => $webhookId ?: null,
                'cursor' => null,  // first sync backfills 3 months
            ],
            'status' => 'active',
        ]);

        $this->info("  PayPal integration saved. Run `php artisan paypal:sync --household={$household->id}` to backfill.");

        return self::SUCCESS;
    }

    private function resolveHousehold(): ?Household
    {
        $id = $this->option('household');
        if ($id) {
            return Household::find((int) $id);
        }

        return Household::first();
    }

    private function pickAccount(Household $household): int
    {
        $accounts = Account::where('household_id', $household->id)->get(['id', 'name', 'type']);
        if ($accounts->isEmpty()) {
            return 0;
        }
        $options = $accounts->mapWithKeys(fn ($a) => [$a->id => $a->name.' ('.$a->type.')'])->all();

        return (int) select(
            label: 'Target Bureau Account for PayPal transactions',
            options: $options,
            hint: 'PayPal purchases land here; bank→PayPal transfers get reconciled automatically.'
        );
    }
}

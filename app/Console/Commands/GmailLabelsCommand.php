<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Support\CurrentHousehold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

use function Laravel\Prompts\multiselect;

class GmailLabelsCommand extends Command
{
    protected $signature = 'integrations:gmail-labels
        {--integration= : Gmail integration id}
        {--labels= : Comma-separated Gmail label IDs (skip interactive picker)}';

    protected $description = 'Pick Gmail labels to watch for an existing Gmail integration — only labeled messages are pulled by mail:sync.';

    public function handle(): int
    {
        $id = (int) $this->option('integration');
        $integration = $id > 0
            ? Integration::withoutGlobalScopes()->where('kind', 'mail')->where('provider', 'gmail')->find($id)
            : Integration::withoutGlobalScopes()->where('kind', 'mail')->where('provider', 'gmail')->first();

        if (! $integration) {
            $this->error('No Gmail integration found. Run /integrations/gmail/connect first.');

            return self::FAILURE;
        }

        CurrentHousehold::set($integration->household);

        $explicit = (string) $this->option('labels');
        if ($explicit !== '') {
            $ids = array_values(array_filter(array_map('trim', explode(',', $explicit))));
            $this->saveLabels($integration, $ids);
            $this->info('  Labels set: '.implode(', ', $ids));

            return self::SUCCESS;
        }

        $labels = $this->fetchLabels($integration);
        if ($labels === []) {
            $this->error('Could not list labels — is the refresh_token still valid?');

            return self::FAILURE;
        }

        $picked = multiselect(
            label: 'Labels to watch',
            options: $labels,
            hint: 'Only messages with these labels feed into Bureau.',
        );

        $this->saveLabels($integration, array_values($picked));
        $this->info('  Labels saved: '.count($picked));

        return self::SUCCESS;
    }

    /**
     * @return array<string, string> id => name
     */
    private function fetchLabels(Integration $integration): array
    {
        $access = $this->accessToken($integration);
        if ($access === null) {
            return [];
        }
        $response = Http::withToken($access)->acceptJson()->get('https://gmail.googleapis.com/gmail/v1/users/me/labels');
        if (! $response->successful()) {
            return [];
        }
        $out = [];
        foreach ((array) $response->json('labels', []) as $row) {
            if (is_array($row) && is_string($row['id'] ?? null) && is_string($row['name'] ?? null)) {
                $out[$row['id']] = $row['name'];
            }
        }

        return $out;
    }

    private function accessToken(Integration $integration): ?string
    {
        $creds = (array) $integration->credentials;
        $refresh = (string) ($creds['refresh_token'] ?? '');
        if ($refresh === '') {
            return null;
        }
        if (isset($creds['access_token'], $creds['access_token_expires_at'])
            && (int) $creds['access_token_expires_at'] > time() + 30) {
            return (string) $creds['access_token'];
        }
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);
        if (! $response->successful()) {
            return null;
        }

        $access = (string) ($response->json('access_token') ?? '');
        $creds['access_token'] = $access;
        $creds['access_token_expires_at'] = time() + (int) ($response->json('expires_in') ?? 3600);
        $integration->credentials = $creds;
        $integration->save();

        return $access ?: null;
    }

    /**
     * @param  array<int, string>  $ids
     */
    private function saveLabels(Integration $integration, array $ids): void
    {
        $settings = (array) ($integration->settings ?? []);
        $settings['label_ids'] = $ids;
        $integration->settings = $settings;
        $integration->save();
    }
}

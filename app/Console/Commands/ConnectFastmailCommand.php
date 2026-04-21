<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Models\Integration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

use function Laravel\Prompts\password;
use function Laravel\Prompts\search;
use function Laravel\Prompts\text;

class ConnectFastmailCommand extends Command
{
    protected $signature = 'integrations:connect-fastmail
        {--household= : Household id to attach this integration to}
        {--token= : API token (skip interactive prompt)}
        {--folder-id= : JMAP mailbox id (skip folder picker)}
        {--session-url=https://api.fastmail.com/.well-known/jmap}';

    protected $description = 'Interactively provision a Fastmail JMAP integration — prompts for token, lists folders, saves the row.';

    public function handle(): int
    {
        $household = $this->resolveHousehold();
        if (! $household) {
            return self::FAILURE;
        }

        $token = (string) ($this->option('token') ?: password(
            label: 'Fastmail API token',
            hint: 'Generate at fastmail.com → Settings → Password & Security → API tokens'
        ));
        if ($token === '') {
            $this->error('Token is required.');

            return self::FAILURE;
        }

        $sessionUrl = (string) $this->option('session-url');
        $session = Http::withToken($token)->acceptJson()->get($sessionUrl);
        if (! $session->successful()) {
            $this->error('Session fetch failed: '.$session->status());

            return self::FAILURE;
        }
        $apiUrl = (string) ($session->json('apiUrl') ?? '');
        $accountId = (string) ($session->json('primaryAccounts.urn:ietf:params:jmap:mail') ?? '');
        if ($apiUrl === '' || $accountId === '') {
            $this->error('Session object missing apiUrl or primary mail account.');

            return self::FAILURE;
        }

        $folderId = (string) ($this->option('folder-id') ?: $this->pickFolder($apiUrl, $token, $accountId));
        if ($folderId === '') {
            $this->error('No folder selected.');

            return self::FAILURE;
        }

        $label = text('Display label', default: 'Fastmail');

        Integration::create([
            'household_id' => $household->id,
            'provider' => 'jmap_fastmail',
            'kind' => 'mail',
            'label' => $label,
            'credentials' => [
                'token' => $token,
                'session_url' => $sessionUrl,
            ],
            'settings' => [
                'account_id' => $accountId,
                'folder_id' => $folderId,
                'last_synced_at' => null,
            ],
            'status' => 'active',
        ]);

        $this->info("  Integration saved. Run `php artisan mail:sync --household={$household->id}` to pull.");

        return self::SUCCESS;
    }

    private function resolveHousehold(): ?Household
    {
        $id = $this->option('household');
        if ($id) {
            return Household::find((int) $id);
        }
        $h = Household::first();
        if (! $h) {
            $this->error('No households exist. Create one first.');

            return null;
        }

        return $h;
    }

    private function pickFolder(string $apiUrl, string $token, string $accountId): string
    {
        $response = Http::withToken($token)->acceptJson()->asJson()->post($apiUrl, [
            'using' => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'],
            'methodCalls' => [
                ['Mailbox/get', ['accountId' => $accountId, 'ids' => null], 'c1'],
            ],
        ]);
        if (! $response->successful()) {
            $this->error('Mailbox/get failed: '.$response->status());

            return '';
        }
        $list = $response->json('methodResponses.0.1.list') ?? [];
        $options = [];
        foreach ((array) $list as $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                $label = (string) ($row['name'] ?? $row['id']);
                $options[$row['id']] = $label;
            }
        }

        return (string) search(
            label: 'Pick a folder to ingest',
            options: fn (string $q) => $q === ''
                ? $options
                : array_filter($options, fn ($v) => stripos($v, $q) !== false),
            hint: 'Only messages arriving in this folder get pulled into Bureau.',
        );
    }
}

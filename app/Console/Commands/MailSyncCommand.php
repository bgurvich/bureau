<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Models\Integration;
use App\Support\CurrentHousehold;
use App\Support\Mail\GmailProvider;
use App\Support\Mail\ImapProvider;
use App\Support\Mail\JmapProvider;
use App\Support\Mail\MailIngester;
use App\Support\Mail\MailProvider;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;

class MailSyncCommand extends Command
{
    protected $signature = 'mail:sync
        {--household= : Restrict to a single household id}
        {--integration= : Restrict to a single integration id}
        {--dry-run : Report what would happen without persisting changes}';

    protected $description = 'Pull new messages from every active mail integration into mail_messages. Deduped by Message-ID across mailboxes.';

    /** @var array<string, class-string<MailProvider>> */
    private const PROVIDER_MAP = [
        'jmap_fastmail' => JmapProvider::class,
        'gmail' => GmailProvider::class,
        'imap' => ImapProvider::class,
    ];

    public function handle(Container $container, MailIngester $ingester): int
    {
        $householdFilter = $this->option('household');
        $integrationFilter = $this->option('integration');
        $dryRun = (bool) $this->option('dry-run');

        $households = Household::query()
            ->when($householdFilter, fn ($q) => $q->where('id', $householdFilter))
            ->get();

        $totalIngested = 0;
        $totalSkipped = 0;

        foreach ($households as $household) {
            CurrentHousehold::set($household);

            $integrations = Integration::query()
                ->where('kind', 'mail')
                ->where('status', 'active')
                ->when($integrationFilter, fn ($q) => $q->where('id', $integrationFilter))
                ->get();

            foreach ($integrations as $integration) {
                $providerClass = self::PROVIDER_MAP[$integration->provider] ?? null;
                if (! $providerClass) {
                    $this->warn("  [{$household->name}] unsupported provider '{$integration->provider}' — skipping");

                    continue;
                }

                $this->line("  [{$household->name}] syncing {$integration->provider} · {$integration->label}");

                try {
                    /** @var MailProvider $provider */
                    $provider = $container->make($providerClass);
                    foreach ($provider->pullSince($integration) as $data) {
                        if ($dryRun) {
                            $this->line("    would ingest: {$data->subject} ({$data->messageId})");
                            $totalIngested++;

                            continue;
                        }
                        $result = $ingester->ingest(
                            household: $household,
                            data: $data,
                            integration: $integration,
                            provider: $provider,
                        );
                        if ($result['created']) {
                            $totalIngested++;
                        } else {
                            $totalSkipped++;
                        }
                    }

                    if (! $dryRun) {
                        $integration->forceFill(['last_error' => null])->save();
                    }
                } catch (\Throwable $e) {
                    $this->error('    failed: '.$e->getMessage());
                    if (! $dryRun) {
                        $integration->forceFill(['last_error' => $e->getMessage()])->save();
                    }
                }
            }
        }

        $this->info("  Ingested {$totalIngested}, skipped {$totalSkipped} (already seen)".($dryRun ? ' · DRY RUN' : ''));

        return self::SUCCESS;
    }
}

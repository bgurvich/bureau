<?php

use App\Models\Integration;
use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * Regex-per-line list of phrases to strip from transaction descriptions
     * before vendor auto-detection. Persisted on `households.data`.
     */
    public string $vendorIgnorePatterns = '';

    public ?string $vendorIgnoreSaved = null;

    public function mount(): void
    {
        $h = CurrentHousehold::get();
        $raw = is_object($h) ? data_get($h->data, 'vendor_ignore_patterns') : null;
        $this->vendorIgnorePatterns = is_string($raw) ? $raw : '';
    }

    public function saveVendorIgnorePatterns(): void
    {
        $h = CurrentHousehold::get();
        if (! $h) {
            return;
        }
        $data = is_array($h->data) ? $h->data : [];
        $data['vendor_ignore_patterns'] = $this->vendorIgnorePatterns;
        $h->forceFill(['data' => $data])->save();

        $this->vendorIgnoreSaved = __('Saved.');
    }

    /**
     * Household / app-wide integrations only. Per-user mail and calendar
     * connectors render on /profile so each page answers a single question:
     * here, "what does the *app* talk to?" (PayPal, Slack, Twilio, …);
     * there, "what does *this user* have linked?" (their Gmail, Fastmail).
     */
    #[Computed]
    public function integrations()
    {
        return Integration::whereNotIn('kind', ['mail', 'calendar'])
            ->orderBy('provider')->orderBy('label')
            ->get();
    }

    public function disconnectIntegration(int $integrationId): void
    {
        Integration::whereNotIn('kind', ['mail', 'calendar'])
            ->where('id', $integrationId)
            ->delete();
        unset($this->integrations);
    }

    public function runBackupNow(): void
    {
        Artisan::call('backup:run', ['--only-db' => true]);
        $name = (string) config('backup.backup.name', 'Laravel');
        session()->flash('backup_ran', __('Backup started — check storage/app/private/:name/ for the latest archive.', ['name' => $name]));
    }

    public function with(): array
    {
        return [
            'backupLastRun' => $this->backupLastRun(),
            'outboundMail' => $this->outboundMail(),
            'localAi' => $this->localAi(),
        ];
    }

    /**
     * Read-only snapshot of outbound-mail config. We never expose secrets —
     * just enough to tell the user "yes, something is wired up" and point
     * them at the ops doc if they need to rotate or rewire.
     *
     * @return array{driver: string, from: ?string, host: ?string, configured: bool}
     */
    private function outboundMail(): array
    {
        $driver = (string) config('mail.default', 'log');
        $from = config('mail.from.address');
        $host = match ($driver) {
            'postmark' => 'api.postmarkapp.com',
            'smtp'     => (string) config('mail.mailers.smtp.host', ''),
            default    => null,
        };

        return [
            'driver' => $driver,
            'from' => is_string($from) && $from !== '' ? $from : null,
            'host' => $host !== '' ? $host : null,
            'configured' => $driver !== 'log' && $driver !== 'array',
        ];
    }

    /**
     * @return array{base_url: ?string, model: ?string, enabled: bool}
     */
    private function localAi(): array
    {
        $base = (string) config('services.lm_studio.base_url', '');
        $model = (string) config('services.lm_studio.model', '');
        $enabled = (bool) config('services.lm_studio.enabled', false);

        return [
            'base_url' => $base !== '' ? $base : null,
            'model' => $model !== '' ? $model : null,
            'enabled' => $enabled && $base !== '',
        ];
    }

    /**
     * Cheapest possible "when was the DB last backed up" — newest file mtime
     * in the spatie backup dir. Good enough for a status badge; if we ever
     * need timezone or size, compute on demand.
     */
    private function backupLastRun(): ?string
    {
        $name = (string) config('backup.backup.name', 'Laravel');
        $dir = storage_path('app/private/'.$name);
        if (! is_dir($dir)) {
            return null;
        }
        $files = glob($dir.'/*.zip');
        if (! $files) {
            return null;
        }
        $newest = max(array_map('filemtime', $files));

        return $newest ? CarbonImmutable::createFromTimestamp($newest)->diffForHumans() : null;
    }
}; ?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Settings')"
        :description="__('App-wide plumbing: household integrations, outbound mail, AI stack, backups. Your personal mail and calendar connectors live on /profile.')">
    </x-ui.page-header>

    @if (session('backup_ran'))
        <div role="status"
             class="rounded-md border border-emerald-800/40 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-300">
            {{ session('backup_ran') }}
        </div>
    @endif

    {{-- Integrations (household / app-wide) ──────────────────────────── --}}
    <section aria-labelledby="integrations-heading" class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
        <header class="mb-4">
            <h3 id="integrations-heading" class="text-sm font-semibold text-neutral-100">{{ __('Household integrations') }}</h3>
            <p class="mt-1 text-xs text-neutral-500">
                {{ __('Bank, notification, and other app-wide services. Credentials stored encrypted. Personal mail and calendar accounts belong on :profile.', ['profile' => '/profile']) }}
            </p>
        </header>
        @if ($this->integrations->isEmpty())
            <p class="text-xs text-neutral-500">{{ __('No household integrations connected yet.') }}</p>
        @else
            <ul class="divide-y divide-neutral-800 rounded-md border border-neutral-800">
                @foreach ($this->integrations as $int)
                    <li class="flex items-center justify-between gap-3 px-3 py-2 text-xs" wire:key="household-integration-{{ $int->id }}">
                        <div class="min-w-0">
                            <div class="text-neutral-100">{{ $int->label ?: $int->provider }}</div>
                            <div class="text-[11px] text-neutral-500">
                                {{ $int->provider }} · {{ $int->kind }} ·
                                <x-ui.row-badge :state="$int->status === 'active' ? 'active' : 'paused'">{{ $int->status }}</x-ui.row-badge>
                                @if ($int->last_synced_at)
                                    · {{ __('synced :when', ['when' => $int->last_synced_at->diffForHumans()]) }}
                                @endif
                            </div>
                        </div>
                        <button type="button" wire:click="disconnectIntegration({{ $int->id }})"
                                wire:confirm="{{ __('Disconnect :n? This removes stored credentials; you\'ll need to reconnect to resume syncing.', ['n' => $int->label ?: $int->provider]) }}"
                                class="rounded border border-rose-800/40 bg-rose-900/20 px-2 py-1 text-rose-200 hover:bg-rose-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Disconnect') }}
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
        <details class="mt-4 rounded-md border border-neutral-800 bg-neutral-950/40 p-3 text-xs text-neutral-400">
            <summary class="cursor-pointer text-neutral-300">{{ __('How to connect') }}</summary>
            <div class="mt-3 space-y-2">
                <p><strong class="text-neutral-200">{{ __('PayPal') }}</strong> —
                   {{ __('Provisioned from the CLI: php artisan integrations:connect-paypal. Credentials are encrypted and paired with a webhook id for signature verification.') }}</p>
                <p><strong class="text-neutral-200">{{ __('Plaid (US banks)') }}</strong> —
                   {{ __('On the roadmap. Bureau targets Plaid as its single bank-sync provider; no connector ships yet.') }}</p>
                <p><strong class="text-neutral-200">{{ __('Slack / Telegram / Twilio') }}</strong> —
                   {{ __('Notification channels listed on /profile are placeholders — the delivery adapters are still to be built.') }}</p>
            </div>
        </details>
    </section>

    {{-- Outbound mail (Postmark / SMTP / log) ──────────────────────────── --}}
    <section aria-labelledby="outbound-mail-heading" class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
        <header class="mb-3 flex items-baseline justify-between gap-4">
            <div>
                <h3 id="outbound-mail-heading" class="text-sm font-semibold text-neutral-100">{{ __('Outbound mail') }}</h3>
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('How Bureau sends reminders, magic-link sign-ins, and alerts. Configured in .env; see docs/ops/outbound-email.md for provider + DNS setup.') }}
                </p>
            </div>
            <x-ui.row-badge :state="$outboundMail['configured'] ? 'active' : 'paused'">
                {{ $outboundMail['configured'] ? __('configured') : __('log only') }}
            </x-ui.row-badge>
        </header>
        <dl class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-3">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Driver') }}</dt>
                <dd class="mt-0.5 font-mono text-neutral-200">{{ $outboundMail['driver'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('From address') }}</dt>
                <dd class="mt-0.5 font-mono text-neutral-200">{{ $outboundMail['from'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Host') }}</dt>
                <dd class="mt-0.5 font-mono text-neutral-200">{{ $outboundMail['host'] ?? '—' }}</dd>
            </div>
        </dl>
    </section>

    {{-- Local AI (LM Studio) ────────────────────────────────────────────── --}}
    <section aria-labelledby="local-ai-heading" class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
        <header class="mb-3 flex items-baseline justify-between gap-4">
            <div>
                <h3 id="local-ai-heading" class="text-sm font-semibold text-neutral-100">{{ __('Local AI (LM Studio)') }}</h3>
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('OCR extraction and other inference run against a local LM Studio server. Configure LM_STUDIO_ENABLED, LM_STUDIO_BASE_URL, and LM_STUDIO_MODEL in .env.') }}
                </p>
            </div>
            <x-ui.row-badge :state="$localAi['enabled'] ? 'active' : 'paused'">
                {{ $localAi['enabled'] ? __('enabled') : __('disabled') }}
            </x-ui.row-badge>
        </header>
        <dl class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Base URL') }}</dt>
                <dd class="mt-0.5 font-mono text-neutral-200">{{ $localAi['base_url'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Model') }}</dt>
                <dd class="mt-0.5 font-mono text-neutral-200">{{ $localAi['model'] ?? '—' }}</dd>
            </div>
        </dl>
    </section>

    {{-- Vendor auto-detect ignore list ───────────────────────────────── --}}
    <section aria-labelledby="vendor-ignore-heading" class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
        <header class="mb-3">
            <h3 id="vendor-ignore-heading" class="text-sm font-semibold text-neutral-100">{{ __('Vendor auto-detect · ignore list') }}</h3>
            <p class="mt-1 text-xs text-neutral-500">
                {{ __('Regex patterns stripped from transaction descriptions before vendor matching. One per line, case-insensitive. Example: "purchase authorized on" turns "Purchase authorized on 07/30 Costco" into just "Costco" for matching and auto-created contact names.') }}
            </p>
        </header>
        <form wire:submit.prevent="saveVendorIgnorePatterns" class="space-y-2">
            <label for="vendor-ignore" class="sr-only">{{ __('Vendor ignore patterns') }}</label>
            <textarea wire:model="vendorIgnorePatterns" id="vendor-ignore" rows="6"
                      placeholder="purchase authorized on&#10;pos purchase&#10;ach transfer (from|to)&#10;recurring payment authorized on"
                      class="w-full resize-y rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 font-mono text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
            <div class="flex items-center justify-between gap-3">
                <span class="text-[11px] text-neutral-500">
                    {{ __('Syntax: PCRE regex body, no delimiters. Broken lines are skipped silently.') }}
                </span>
                <div class="flex items-center gap-2">
                    @if ($vendorIgnoreSaved)
                        <span role="status" class="text-[11px] text-emerald-300">{{ $vendorIgnoreSaved }}</span>
                    @endif
                    <button type="submit"
                            class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-200 hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <span wire:loading.remove wire:target="saveVendorIgnorePatterns">{{ __('Save') }}</span>
                        <span wire:loading wire:target="saveVendorIgnorePatterns">{{ __('Saving…') }}</span>
                    </button>
                </div>
            </div>
        </form>
    </section>

    {{-- Backups ───────────────────────────────────────────────────────── --}}
    <section aria-labelledby="backup-heading" class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
        <header class="mb-3 flex items-baseline justify-between gap-4">
            <div>
                <h3 id="backup-heading" class="text-sm font-semibold text-neutral-100">{{ __('Backups') }}</h3>
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('Nightly DB + media snapshots at 03:30 UTC (see :cfg). You can trigger one now.', ['cfg' => 'config/backup.php']) }}
                </p>
            </div>
            <button type="button" wire:click="runBackupNow"
                    wire:confirm="{{ __('Run backup now? DB-only; takes seconds.') }}"
                    class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-200 hover:border-neutral-500 hover:bg-neutral-800">
                {{ __('Back up now') }}
            </button>
        </header>
        <div class="font-mono text-[11px] text-neutral-500">
            @if ($backupLastRun)
                {{ __('Last backup: :when', ['when' => $backupLastRun]) }}
            @else
                {{ __('No backup archives found yet.') }}
            @endif
        </div>
    </section>

    {{-- Profile link — keep settings from duplicating per-user preferences. --}}
    <section class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5 text-xs text-neutral-500">
        {{ __('Looking for name / locale / timezone / theme / currency / notification preferences, Gmail or Fastmail connection, or passkeys?') }}
        <a href="{{ route('profile') }}" class="text-sky-300 underline-offset-2 hover:underline">{{ __('They\'re on /profile.') }}</a>
    </section>
</div>

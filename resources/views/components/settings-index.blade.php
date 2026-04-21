<?php

use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function integrations()
    {
        return Integration::orderBy('provider')->orderBy('label')->get();
    }

    public function disconnectIntegration(int $integrationId): void
    {
        Integration::where('id', $integrationId)->delete();
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
        :description="__('App-level preferences: integrations and backups. Per-user profile (name, locale, theme, currency, notifications) lives on /profile.')">
    </x-ui.page-header>

    @if (session('backup_ran'))
        <div role="status"
             class="rounded-md border border-emerald-800/40 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-300">
            {{ session('backup_ran') }}
        </div>
    @endif

    {{-- Integrations ──────────────────────────────────────────────────── --}}
    <section aria-labelledby="integrations-heading" class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
        <header class="mb-4 flex items-baseline justify-between">
            <div>
                <h3 id="integrations-heading" class="text-sm font-semibold text-neutral-100">{{ __('Integrations') }}</h3>
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('Connected external services. Credentials stored encrypted.') }}
                </p>
            </div>
            <a href="{{ route('integrations.gmail.connect') }}"
               class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-200 hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ __('Connect Gmail') }}
            </a>
        </header>
        @if ($this->integrations->isEmpty())
            <p class="text-xs text-neutral-500">{{ __('No integrations connected.') }}</p>
        @else
            <ul class="divide-y divide-neutral-800 rounded-md border border-neutral-800">
                @foreach ($this->integrations as $int)
                    <li class="flex items-center justify-between gap-3 px-3 py-2 text-xs">
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
        {{ __('Looking for name / locale / timezone / theme / currency / notification preferences?') }}
        <a href="{{ route('profile') }}" class="text-sky-300 underline-offset-2 hover:underline">{{ __('They\'re on /profile.') }}</a>
    </section>
</div>

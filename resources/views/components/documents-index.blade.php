<?php

use App\Models\Document;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Documents'])]
class extends Component
{
    #[Url(as: 'kind')]
    public string $kindFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public bool $inCaseOfOnly = false;

    public bool $expiringOnly = false;

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->documents, $this->counts);
    }

    #[Computed]
    public function documents(): Collection
    {
        $cutoff = CarbonImmutable::today()->addDays(90)->toDateString();

        return Document::query()
            ->with('holder:id,name')
            ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter))
            ->when($this->inCaseOfOnly, fn ($q) => $q->where('in_case_of_pack', true))
            ->when($this->expiringOnly, fn ($q) => $q
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<=', $cutoff)
            )
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('label', 'like', $term)
                    ->orWhere('number', 'like', $term)
                    ->orWhere('issuer', 'like', $term)
                );
            })
            ->orderByRaw('expires_on IS NULL, expires_on')
            ->orderBy('kind')
            ->get();
    }

    #[Computed]
    public function counts(): array
    {
        $today = CarbonImmutable::today()->toDateString();

        return [
            'total' => Document::count(),
            'expiring30' => Document::whereNotNull('expires_on')
                ->whereDate('expires_on', '<=', CarbonImmutable::today()->addDays(30)->toDateString())
                ->whereDate('expires_on', '>=', $today)
                ->count(),
            'expired' => Document::whereNotNull('expires_on')
                ->whereDate('expires_on', '<', $today)
                ->count(),
            'in_case_of' => Document::where('in_case_of_pack', true)->count(),
        ];
    }

    /** @return array<string, string> */
    #[Computed]
    public function kinds(): array
    {
        return \App\Support\Enums::documentKinds();
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Documents') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('IDs, certificates, and the in-case-of-emergency pack.') }}</p>
        </div>
        <x-ui.new-record-button type="document" :label="__('New document')" shortcut="D" />
    </header>

    <dl class="flex gap-5 text-xs">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Expiring ≤ 30d') }}</dt>
                <dd class="mt-0.5 tabular-nums {{ $this->counts['expiring30'] > 0 ? 'text-amber-400' : 'text-neutral-500' }}">{{ $this->counts['expiring30'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Expired') }}</dt>
                <dd class="mt-0.5 tabular-nums {{ $this->counts['expired'] > 0 ? 'text-rose-400' : 'text-neutral-500' }}">{{ $this->counts['expired'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('In-case-of pack') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->counts['in_case_of'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('On file') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->counts['total'] }}</dd>
            </div>
    </dl>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="d-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="d-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Label, number, issuer…') }}">
        </div>
        <div>
            <label for="d-kind" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Kind') }}</label>
            <select wire:model.live="kindFilter" id="d-kind"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach ($this->kinds as $k => $label)
                    <option value="{{ $k }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <label class="flex items-center gap-2 text-xs text-neutral-300">
            <input wire:model.live="inCaseOfOnly" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('In-case-of pack only') }}
        </label>
        <label class="flex items-center gap-2 text-xs text-neutral-300">
            <input wire:model.live="expiringOnly" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Expiring soon') }}
        </label>
    </form>

    @if ($this->documents->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No documents match those filters.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->documents as $d)
                @php
                    $expiresOn = $d->expires_on ? CarbonImmutable::parse($d->expires_on) : null;
                    $daysLeft = $expiresOn ? (int) now()->startOfDay()->diffInDays($expiresOn, absolute: false) : null;
                    $expiryClass = match (true) {
                        $daysLeft !== null && $daysLeft < 0 => 'text-rose-400',
                        $daysLeft !== null && $daysLeft <= 30 => 'text-rose-400',
                        $daysLeft !== null && $daysLeft <= 90 => 'text-amber-400',
                        default => 'text-neutral-500',
                    };
                    $label = $d->label ?? ($this->kinds[$d->kind] ?? ucfirst($d->kind));
                @endphp
                <li>
                    <button type="button"
                            wire:click="$dispatch('inspector-open', { type: 'document', id: {{ $d->id }} })"
                            class="flex w-full items-start justify-between gap-4 px-4 py-3 text-left text-sm transition hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="truncate text-neutral-100">{{ $label }}</span>
                            <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ $d->kind }}</span>
                            @if ($d->in_case_of_pack)
                                <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400" aria-label="{{ __('In-case-of pack') }}">
                                    {{ __('ICE') }}
                                </span>
                            @endif
                        </div>
                        <div class="mt-0.5 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                            @if ($d->number)
                                <span class="tabular-nums">#{{ $d->number }}</span>
                            @endif
                            @if ($d->issuer)
                                <span>{{ $d->issuer }}</span>
                            @endif
                            @if ($d->holder)
                                <span>{{ $d->holder->name }}</span>
                            @endif
                            @if ($d->issued_on)
                                <span>{{ __('Issued') }} {{ Formatting::date($d->issued_on) }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="shrink-0 text-right">
                        @if ($expiresOn)
                            <div class="text-xs tabular-nums {{ $expiryClass }}">
                                {{ Formatting::date($expiresOn) }}
                            </div>
                            <div class="text-[10px] uppercase tracking-wider {{ $expiryClass }}">
                                @if ($daysLeft < 0)
                                    {{ __('Expired') }}
                                @else
                                    {{ $daysLeft }}d {{ __('left') }}
                                @endif
                            </div>
                        @endif
                    </div>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>

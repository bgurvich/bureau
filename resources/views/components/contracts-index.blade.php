<?php

use App\Models\Contract;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Contracts'])]
class extends Component
{
    #[Url(as: 'kind')]
    public string $kindFilter = '';

    #[Url(as: 'state')]
    public string $stateFilter = 'active';

    #[Url(as: 'q')]
    public string $search = '';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->contracts, $this->monthlyBurn, $this->expiringSoon);
    }

    #[Computed]
    public function contracts(): Collection
    {
        return Contract::query()
            ->with(['contacts:id,display_name'])
            ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter))
            ->when($this->stateFilter !== '', fn ($q) => $q->where('state', $this->stateFilter))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term)
                );
            })
            ->orderByRaw('ends_on IS NULL, ends_on')
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function monthlyBurn(): float
    {
        return (float) $this->contracts
            ->where('state', 'active')
            ->sum('monthly_cost_amount');
    }

    #[Computed]
    public function expiringSoon(): int
    {
        $cutoff = CarbonImmutable::today()->addDays(30);

        return Contract::where('state', 'active')
            ->whereNotNull('ends_on')
            ->whereDate('ends_on', '<=', $cutoff->toDateString())
            ->count();
    }

    #[Computed]
    public function currency(): string
    {
        return CurrentHousehold::get()?->default_currency ?? 'USD';
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Contracts') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Agreements, subscriptions, insurance, leases.') }}</p>
        </div>
        <x-ui.new-record-button type="contract" :label="__('New contract')" />
    </header>

    <dl class="flex gap-5 text-xs">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Monthly burn') }}</dt>
                <dd class="mt-0.5 tabular-nums text-rose-400">{{ Formatting::money($this->monthlyBurn, $this->currency) }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Expiring ≤ 30d') }}</dt>
                <dd class="mt-0.5 tabular-nums {{ $this->expiringSoon > 0 ? 'text-amber-400' : 'text-neutral-500' }}">{{ $this->expiringSoon }}</dd>
            </div>
    </dl>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="c-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="c-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Title or description…') }}">
        </div>
        <div>
            <label for="c-kind" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Kind') }}</label>
            <select wire:model.live="kindFilter" id="c-kind"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (App\Support\Enums::contractKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="c-state" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('State') }}</label>
            <select wire:model.live="stateFilter" id="c-state"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (App\Support\Enums::contractStates() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if ($this->contracts->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No contracts match those filters.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->contracts as $c)
                @php
                    $endsOn = $c->ends_on ? CarbonImmutable::parse($c->ends_on) : null;
                    $daysLeft = $endsOn ? (int) now()->startOfDay()->diffInDays($endsOn, absolute: false) : null;
                    $expiryClass = match (true) {
                        $daysLeft !== null && $daysLeft < 0 => 'text-rose-400',
                        $daysLeft !== null && $daysLeft <= 30 => 'text-rose-400',
                        $daysLeft !== null && $daysLeft <= 90 => 'text-amber-400',
                        default => 'text-neutral-500',
                    };
                    $counterparty = $c->contacts->first();
                @endphp
                <li>
                    <button type="button"
                            wire:click="$dispatch('inspector-open', { type: 'contract', id: {{ $c->id }} })"
                            class="flex w-full items-start gap-4 px-4 py-3 text-left text-sm transition hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2">
                                <span class="truncate text-neutral-100">{{ $c->title }}</span>
                                <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ $c->kind }}</span>
                                @if ($c->auto_renews)
                                    <span aria-label="{{ __('Auto-renews') }}" class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">↻</span>
                                @endif
                                @if ($c->trial_ends_on)
                                    <span class="shrink-0 rounded bg-amber-900/40 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-amber-300">{{ __('trial') }}</span>
                                @endif
                            </div>
                            <div class="mt-0.5 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                                @if ($counterparty)
                                    <span>{{ $counterparty->display_name }}</span>
                                @endif
                                @if ($c->starts_on)
                                    <span>{{ __('Since') }} {{ Formatting::date($c->starts_on) }}</span>
                                @endif
                                @if ($c->trial_ends_on)
                                    @php
                                        $trialDays = (int) now()->startOfDay()->diffInDays($c->trial_ends_on, absolute: false);
                                        $trialClass = match (true) {
                                            $trialDays < 0 => 'text-neutral-600',
                                            $trialDays <= 7 => 'text-rose-400',
                                            $trialDays <= 30 => 'text-amber-400',
                                            default => 'text-amber-300',
                                        };
                                    @endphp
                                    <span class="{{ $trialClass }}">@if ($trialDays < 0){{ __('trial ended') }}@else{{ __('Cancel by') }} {{ Formatting::date($c->trial_ends_on) }} · {{ $trialDays }}d @endif</span>
                                @endif
                                @if ($endsOn)
                                    <span class="{{ $expiryClass }}">
                                        @if ($daysLeft < 0)
                                            {{ __('Ended :date', ['date' => Formatting::date($endsOn)]) }}
                                        @else
                                            {{ __('Ends :date', ['date' => Formatting::date($endsOn)]) }} · {{ $daysLeft }}d
                                        @endif
                                    </span>
                                @endif
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            @if ($c->monthly_cost_amount !== null)
                                <div class="text-sm tabular-nums text-neutral-100">
                                    {{ Formatting::money((float) $c->monthly_cost_amount, $c->monthly_cost_currency ?? $this->currency) }}
                                </div>
                                <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('per month') }}</div>
                            @endif
                        </div>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>

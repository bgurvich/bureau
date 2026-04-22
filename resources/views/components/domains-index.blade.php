<?php

use App\Models\Domain;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Domains'])]
class extends Component
{
    /** Filter: '' | 'expired' | 'expiring' (within 30d). */
    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->domains, $this->totalAnnualCost);
    }

    /** @return Collection<int, Domain> */
    #[Computed]
    public function domains(): Collection
    {
        /** @var Collection<int, Domain> $list */
        $list = Domain::query()
            ->with('registrant:id,display_name')
            ->when($this->statusFilter === 'expired', fn ($q) => $q
                ->whereNotNull('expires_on')
                ->where('expires_on', '<', now()->toDateString()))
            ->when($this->statusFilter === 'expiring', fn ($q) => $q
                ->whereNotNull('expires_on')
                ->whereBetween('expires_on', [now()->toDateString(), now()->addDays(30)->toDateString()]))
            ->orderBy('name')
            ->get();

        return $list;
    }

    #[Computed]
    public function totalAnnualCost(): float
    {
        return (float) $this->domains->sum(fn (Domain $d) => (float) ($d->annual_cost ?? 0));
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
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Domains') }}</h2>
            <p class="mt-1 text-sm text-neutral-500">{{ __('Web and DNS domains you own — registrar, renewal dates, annual cost.') }}</p>
        </div>
        <x-ui.new-record-button type="domain" :label="__('New domain')" shortcut="D" />
    </header>

    <dl class="flex gap-5 text-sm">
        <div>
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Annual cost') }}</dt>
            <dd class="mt-0.5 tabular-nums text-neutral-200">{{ Formatting::money($this->totalAnnualCost, $this->currency) }}</dd>
        </div>
        <div>
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('On file') }}</dt>
            <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->domains->count() }}</dd>
        </div>
    </dl>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="dom-status" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Status') }}</label>
            <select wire:model.live="statusFilter" id="dom-status"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                <option value="expiring">{{ __('Expiring ≤ 30d') }}</option>
                <option value="expired">{{ __('Expired') }}</option>
            </select>
        </div>
    </form>

    @if ($this->domains->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No domains on file yet.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->domains as $d)
                @php
                    $expDays = $d->expires_on
                        ? (int) now()->startOfDay()->diffInDays($d->expires_on, absolute: false)
                        : null;
                    $expClass = match (true) {
                        $expDays === null => 'text-neutral-500',
                        $expDays < 0 => 'text-rose-400',
                        $expDays <= 30 => 'text-rose-400',
                        $expDays <= 90 => 'text-amber-400',
                        default => 'text-neutral-500',
                    };
                @endphp
                <x-ui.inspector-row type="domain" :id="$d->id" :label="$d->name" class="flex items-start gap-4 px-4 py-3 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="truncate font-mono text-neutral-100">{{ $d->name }}</span>
                            @if ($d->auto_renew)
                                <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-emerald-400">{{ __('auto-renew') }}</span>
                            @endif
                        </div>
                        <div class="mt-0.5 flex flex-wrap gap-3 text-sm text-neutral-500">
                            @if ($d->registrar)
                                <span>{{ $d->registrar }}</span>
                            @endif
                            @if ($d->registrant)
                                <span>{{ $d->registrant->display_name }}</span>
                            @endif
                            @if ($d->registered_on)
                                <span>{{ __('Since') }} {{ Formatting::date($d->registered_on) }}</span>
                            @endif
                            @if ($d->expires_on)
                                <span class="{{ $expClass }} tabular-nums">
                                    {{ __('Exp.') }} {{ Formatting::date($d->expires_on) }}
                                    @if ($expDays !== null)
                                        @if ($expDays < 0)
                                            · {{ __('expired') }}
                                        @else
                                            · {{ $expDays }}d
                                        @endif
                                    @endif
                                </span>
                            @endif
                        </div>
                    </div>
                    <div class="shrink-0 text-right">
                        @if ($d->annual_cost !== null)
                            <div class="text-sm tabular-nums text-neutral-100">
                                {{ Formatting::money((float) $d->annual_cost, $this->currency) }}<span class="text-[10px] text-neutral-500">/yr</span>
                            </div>
                        @endif
                    </div>
                </x-ui.inspector-row>
            @endforeach
        </ul>
    @endif
</div>

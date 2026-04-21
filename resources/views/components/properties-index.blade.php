<?php

use App\Models\Property;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Properties'])]
class extends Component
{
    #[Url(as: 'kind')]
    public string $kindFilter = '';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->properties, $this->totalValue);
    }

    #[Computed]
    public function properties(): Collection
    {
        return Property::query()
            ->with('latestValuation')
            ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter))
            ->orderBy('kind')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function totalValue(): float
    {
        return (float) $this->properties
            ->map(fn (Property $p) => (float) ($p->latestValuation?->value ?? $p->purchase_price ?? 0))
            ->sum();
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
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Properties') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Homes, rentals, land, and other owned real estate.') }}</p>
        </div>
        <x-ui.new-record-button type="property" :label="__('New property')" shortcut="H" />
    </header>

    <dl class="flex gap-5 text-xs">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Estimated value') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ Formatting::money($this->totalValue, $this->currency) }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('On file') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->properties->count() }}</dd>
            </div>
    </dl>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="pr-kind" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Kind') }}</label>
            <select wire:model.live="kindFilter" id="pr-kind"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (App\Support\Enums::propertyKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if ($this->properties->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No properties yet.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->properties as $p)
                @php
                    $addr = is_array($p->address) ? $p->address : [];
                    $addrLine = trim(implode(', ', array_filter([
                        $addr['line1'] ?? null,
                        $addr['city'] ?? null,
                        $addr['region'] ?? null,
                        $addr['postcode'] ?? null,
                    ])));
                    $latest = $p->latestValuation;
                    $currentValue = $latest?->value ?? $p->purchase_price;
                    $delta = ($latest && $p->purchase_price)
                        ? (float) $latest->value - (float) $p->purchase_price
                        : null;
                @endphp
                <li>
                    <button type="button"
                            wire:click="$dispatch('inspector-open', {{ json_encode(['type' => 'property', 'id' => $p->id]) }})"
                            class="flex w-full items-start gap-4 px-4 py-3 text-left text-sm transition hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2">
                                <span class="truncate text-neutral-100">{{ $p->name }}</span>
                                <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ $p->kind }}</span>
                                @if ($p->disposed_on)
                                    <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Disposed') }}</span>
                                @endif
                            </div>
                            <div class="mt-0.5 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                                @if ($addrLine)
                                    <span>{{ $addrLine }}</span>
                                @endif
                                @if ($p->size_value)
                                    <span class="tabular-nums">{{ number_format((float) $p->size_value, 0) }} {{ $p->size_unit }}</span>
                                @endif
                                @if ($p->acquired_on)
                                    <span>{{ __('Since') }} {{ Formatting::date($p->acquired_on) }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            @if ($currentValue !== null)
                                <div class="text-sm tabular-nums text-neutral-100">
                                    {{ Formatting::money((float) $currentValue, $latest?->currency ?? $p->purchase_currency ?? $this->currency) }}
                                </div>
                                @if ($delta !== null)
                                    <div class="text-[10px] tabular-nums {{ $delta >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                                        {{ $delta >= 0 ? '+' : '' }}{{ Formatting::money($delta, $latest?->currency ?? $p->purchase_currency ?? $this->currency) }}
                                    </div>
                                @elseif ($latest)
                                    <div class="text-[10px] text-neutral-500">{{ __('as of') }} {{ Formatting::date($latest->as_of) }}</div>
                                @endif
                            @endif
                        </div>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>

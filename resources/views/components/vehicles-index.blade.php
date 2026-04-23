<?php

use App\Models\Vehicle;
use App\Support\CurrentHousehold;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Vehicles'])]
class extends Component
{
    #[Url(as: 'kind')]
    public string $kindFilter = '';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->vehicles, $this->totalValue);
    }

    #[Computed]
    public function vehicles(): Collection
    {
        return Vehicle::query()
            ->with(['latestValuation', 'primaryUser:id,name'])
            ->withCount('serviceLogs')
            ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter))
            ->orderBy('kind')
            ->orderBy('make')
            ->orderBy('model')
            ->get();
    }

    #[Computed]
    public function totalValue(): float
    {
        return (float) $this->vehicles
            ->map(fn (Vehicle $v) => (float) ($v->latestValuation?->value ?? $v->purchase_price ?? 0))
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
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Vehicles') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Cars, bikes, and anything else with wheels or a hull.') }}</p>
        </div>
        <x-ui.new-record-button type="vehicle" :label="__('New vehicle')" shortcut="V" />
    </header>

    <dl class="flex gap-5 text-xs">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Estimated value') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ \App\Support\Formatting::money($this->totalValue, $this->currency) }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('On file') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->vehicles->count() }}</dd>
            </div>
    </dl>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="vh-kind" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Kind') }}</label>
            <select wire:model.live="kindFilter" id="vh-kind"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (App\Support\Enums::vehicleKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if ($this->vehicles->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No vehicles yet.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->vehicles as $v)
                @php
                    $label = trim(implode(' ', array_filter([$v->year, $v->make, $v->model]))) ?: __('Vehicle');
                    $latest = $v->latestValuation;
                    $currentValue = $latest?->value ?? $v->purchase_price;
                    $delta = ($latest && $v->purchase_price)
                        ? (float) $latest->value - (float) $v->purchase_price
                        : null;
                @endphp
                <x-ui.inspector-row type="vehicle" :id="$v->id" :label="$label" class="flex items-start gap-4 px-4 py-3 text-sm">
                    <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2">
                                <span class="truncate text-neutral-100">{{ $label }}</span>
                                <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ $v->kind }}</span>
                                @if ($v->color)
                                    <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] text-neutral-400">{{ $v->color }}</span>
                                @endif
                            </div>
                            <div class="mt-0.5 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                                @if ($v->license_plate)
                                    <span class="tabular-nums">{{ $v->license_plate }}@if($v->license_jurisdiction) · {{ $v->license_jurisdiction }}@endif</span>
                                @endif
                                @if ($v->odometer)
                                    <span class="tabular-nums">{{ number_format($v->odometer) }} {{ $v->odometer_unit }}</span>
                                @endif
                                @if (($v->service_logs_count ?? 0) > 0)
                                    <a href="{{ route('assets.vehicle_services', ['vehicle' => $v->id]) }}"
                                       wire:navigate
                                       @click.stop
                                       class="tabular-nums text-neutral-400 underline-offset-2 hover:text-neutral-200 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                        {{ __(':n service :label', ['n' => $v->service_logs_count, 'label' => $v->service_logs_count === 1 ? __('log') : __('logs')]) }}
                                    </a>
                                @endif
                                @if ($v->primaryUser)
                                    <span>{{ $v->primaryUser->name }}</span>
                                @endif
                                @if ($v->acquired_on)
                                    <span>{{ __('Since') }} {{ \App\Support\Formatting::date($v->acquired_on) }}</span>
                                @endif
                                @if ($v->registration_expires_on)
                                    @php
                                        $regDays = (int) now()->startOfDay()->diffInDays($v->registration_expires_on, absolute: false);
                                        $regClass = match (true) {
                                            $regDays < 0 => 'text-rose-400',
                                            $regDays <= 30 => 'text-rose-400',
                                            $regDays <= 90 => 'text-amber-400',
                                            default => 'text-neutral-500',
                                        };
                                    @endphp
                                    <span class="{{ $regClass }}">{{ __('Reg.') }} {{ \App\Support\Formatting::date($v->registration_expires_on) }}@if ($regDays < 0) · {{ __('expired') }}@else · {{ $regDays }}d @endif</span>
                                @endif
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            @if ($currentValue !== null)
                                <div class="text-sm tabular-nums text-neutral-100">
                                    {{ \App\Support\Formatting::money((float) $currentValue, $latest?->currency ?? $v->purchase_currency ?? $this->currency) }}
                                </div>
                                @if ($delta !== null)
                                    <div class="text-[10px] tabular-nums {{ $delta >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                                        {{ $delta >= 0 ? '+' : '' }}{{ \App\Support\Formatting::money($delta, $latest?->currency ?? $v->purchase_currency ?? $this->currency) }}
                                    </div>
                                @endif
                            @endif
                        </div>
                </x-ui.inspector-row>
            @endforeach
        </ul>
    @endif
</div>

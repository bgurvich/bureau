<?php

use App\Models\Vehicle;
use App\Models\VehicleServiceLog;
use App\Support\CurrentHousehold;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Vehicle services'])]
class extends Component
{
    #[Url(as: 'vehicle')]
    public ?int $vehicleFilter = null;

    #[Url(as: 'kind')]
    public string $kindFilter = '';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->logs, $this->totalCost);
    }

    /** @return Collection<int, VehicleServiceLog> */
    #[Computed]
    public function logs(): Collection
    {
        /** @var Collection<int, VehicleServiceLog> $list */
        $list = VehicleServiceLog::query()
            ->with(['vehicle:id,make,model,year', 'providerContact:id,display_name'])
            ->when($this->vehicleFilter !== null, fn ($q) => $q->where('vehicle_id', $this->vehicleFilter))
            ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter))
            ->orderByDesc('service_date')
            ->orderByDesc('id')
            ->get();

        return $list;
    }

    #[Computed]
    public function totalCost(): float
    {
        return (float) $this->logs->sum(fn (VehicleServiceLog $s) => (float) ($s->cost ?? 0));
    }

    #[Computed]
    public function currency(): string
    {
        return CurrentHousehold::get()?->default_currency ?? 'USD';
    }

    /** @return Collection<int, Vehicle> */
    #[Computed]
    public function vehicles(): Collection
    {
        /** @var Collection<int, Vehicle> $list */
        $list = Vehicle::orderBy('make')->orderBy('model')
            ->get(['id', 'make', 'model', 'year']);

        return $list;
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Vehicle services')"
        :description="__('Maintenance history — oil changes, tire rotations, repairs. Attach the shop invoice to each row.')">
        <x-ui.new-record-button type="vehicle_service_log" :label="__('New service')" />
    </x-ui.page-header>

    <dl class="flex gap-5 text-sm">
        <div>
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Total cost') }}</dt>
            <dd class="mt-0.5 tabular-nums text-neutral-200">{{ \App\Support\Formatting::money($this->totalCost, $this->currency) }}</dd>
        </div>
        <div>
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('On file') }}</dt>
            <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->logs->count() }}</dd>
        </div>
    </dl>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="vs-veh" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Vehicle') }}</label>
            <select wire:model.live="vehicleFilter" id="vs-veh"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach ($this->vehicles as $v)
                    @php
                        $label = trim(implode(' ', array_filter([$v->year, $v->make, $v->model])))
                            ?: __('Vehicle #:id', ['id' => $v->id]);
                    @endphp
                    <option value="{{ $v->id }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="vs-kind" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Kind') }}</label>
            <select wire:model.live="kindFilter" id="vs-kind"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (\App\Support\Enums::vehicleServiceKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if ($this->logs->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No service records yet.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->logs as $s)
                @php
                    $kindLabel = \App\Support\Enums::vehicleServiceKinds()[$s->kind] ?? $s->kind;
                    $vehicle = $s->vehicle;
                    $vLabel = $vehicle
                        ? (trim(implode(' ', array_filter([$vehicle->year, $vehicle->make, $vehicle->model]))) ?: __('Vehicle'))
                        : __('(removed)');
                @endphp
                <x-ui.inspector-row type="vehicle_service_log" :id="$s->id" :label="$kindLabel" class="flex items-start gap-4 px-4 py-3 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-300">{{ $kindLabel }}</span>
                            <span class="truncate font-medium text-neutral-100">{{ $vLabel }}</span>
                            <span class="text-xs text-neutral-500">{{ \App\Support\Formatting::date($s->service_date) }}</span>
                        </div>
                        <div class="mt-0.5 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                            @if ($s->label)<span>{{ $s->label }}</span>@endif
                            @if ($s->odometer !== null)<span class="tabular-nums">{{ number_format($s->odometer) }} {{ $s->odometer_unit }}</span>@endif
                            @if ($s->providerContact)<span>{{ $s->providerContact->display_name }}</span>@endif
                        </div>
                    </div>
                    @if ($s->cost !== null)
                        <div class="shrink-0 text-right text-sm tabular-nums text-neutral-100">
                            {{ \App\Support\Formatting::money((float) $s->cost, $s->currency ?? $this->currency) }}
                        </div>
                    @endif
                </x-ui.inspector-row>
            @endforeach
        </ul>
    @endif
</div>

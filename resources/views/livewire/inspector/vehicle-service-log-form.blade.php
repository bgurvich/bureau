<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-vs-veh" class="mb-1 block text-xs text-neutral-400">{{ __('Vehicle') }}</label>
            <select wire:model="vehicle_id" id="i-vs-veh" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">—</option>
                @foreach ($this->vehicles as $v)
                    @php
                        $label = trim(implode(' ', array_filter([$v->year, $v->make, $v->model])))
                            ?: __('Vehicle #:id', ['id' => $v->id]);
                    @endphp
                    <option value="{{ $v->id }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('vehicle_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-vs-date" class="mb-1 block text-xs text-neutral-400">{{ __('Service date') }}</label>
            <input wire:model="service_date" id="i-vs-date" type="date" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('service_date')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div>
        <label for="i-vs-kind" class="mb-1 block text-xs text-neutral-400">{{ __('Kind') }}</label>
        <select wire:model="kind" id="i-vs-kind" required
                class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @foreach (App\Support\Enums::vehicleServiceKinds() as $v => $l)
                <option value="{{ $v }}">{{ $l }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="i-vs-label" class="mb-1 block text-xs text-neutral-400">{{ __('Label') }}</label>
        <input wire:model="label" id="i-vs-label" type="text"
               placeholder="{{ __('Optional — e.g. “summer tires on”, “front brakes only”') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>

    <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
            <label for="i-vs-odo" class="mb-1 block text-xs text-neutral-400">{{ __('Odometer') }}</label>
            <input wire:model="odometer" id="i-vs-odo" type="number" min="0" step="1"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-vs-unit" class="mb-1 block text-xs text-neutral-400">{{ __('Unit') }}</label>
            <select wire:model="odometer_unit" id="i-vs-unit"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="mi">{{ __('mi') }}</option>
                <option value="km">{{ __('km') }}</option>
            </select>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-vs-cost" class="mb-1 block text-xs text-neutral-400">{{ __('Cost') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $currency }}</span>
            </label>
            <input wire:model="cost" id="i-vs-cost" type="number" step="0.01"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-vs-prov" class="mb-1 block text-xs text-neutral-400">{{ __('Shop / provider') }}</label>
            <x-ui.searchable-select
                id="i-vs-prov"
                model="provider_contact_id"
                :options="['' => '—'] + $this->contacts->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
                placeholder="—"
                allow-create
                create-method="createCounterparty"
                edit-inspector-type="contact" />
        </div>
    </div>

    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

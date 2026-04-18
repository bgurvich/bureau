<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div class="grid grid-cols-4 gap-3">
        <div>
            <label for="i-v-kind" class="mb-1 block text-xs text-neutral-400">{{ __('Kind') }}</label>
            <select wire:model="vehicle_kind" id="i-v-kind" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::vehicleKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="i-v-year" class="mb-1 block text-xs text-neutral-400">{{ __('Year') }}</label>
            <input wire:model="vehicle_year" id="i-v-year" type="number"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div class="col-span-2">
            <label for="i-v-color" class="mb-1 block text-xs text-neutral-400">{{ __('Color') }}</label>
            <input wire:model="vehicle_color" id="i-v-color" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-v-make" class="mb-1 block text-xs text-neutral-400">{{ __('Make') }}</label>
            <input wire:model="vehicle_make" id="i-v-make" type="text" autofocus
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-v-model" class="mb-1 block text-xs text-neutral-400">{{ __('Model') }}</label>
            <input wire:model="vehicle_model" id="i-v-model" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div>
        <label for="i-v-vin" class="mb-1 block text-xs text-neutral-400">{{ __('VIN') }}</label>
        <input wire:model="vehicle_vin" id="i-v-vin" type="text" maxlength="17"
               autocomplete="off" spellcheck="false"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 font-mono text-sm uppercase tracking-wider text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('vehicle_vin')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>
    <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
            <label for="i-v-plate" class="mb-1 block text-xs text-neutral-400">{{ __('License plate') }}</label>
            <input wire:model="vehicle_license_plate" id="i-v-plate" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-v-juri" class="mb-1 block text-xs text-neutral-400">{{ __('Jurisdiction') }}</label>
            <input wire:model="vehicle_license_jurisdiction" id="i-v-juri" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm uppercase text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
            <label for="i-v-odo" class="mb-1 block text-xs text-neutral-400">{{ __('Odometer') }}</label>
            <input wire:model="vehicle_odometer" id="i-v-odo" type="number"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-v-ounit" class="mb-1 block text-xs text-neutral-400">{{ __('Unit') }}</label>
            <select wire:model="vehicle_odometer_unit" id="i-v-ounit"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::vehicleOdometerUnits() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <hr class="border-neutral-800">
    <h4 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Registration') }}</h4>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-v-regexp" class="mb-1 block text-xs text-neutral-400">{{ __('Expires on') }}</label>
            <input wire:model="vehicle_registration_expires_on" id="i-v-regexp" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-v-regfee" class="mb-1 block text-xs text-neutral-400">{{ __('Renewal fee') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $vehicle_registration_fee_currency }}</span>
            </label>
            <input wire:model="vehicle_registration_fee_amount" id="i-v-regfee" type="number" step="0.01"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <hr class="border-neutral-800">
    <h4 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Purchase') }}</h4>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-v-acq" class="mb-1 block text-xs text-neutral-400">{{ __('Acquired on') }}</label>
            <input wire:model="vehicle_acquired_on" id="i-v-acq" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-v-pp" class="mb-1 block text-xs text-neutral-400">{{ __('Price') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $vehicle_purchase_currency }}</span>
            </label>
            <input wire:model="vehicle_purchase_price" id="i-v-pp" type="number" step="0.01"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    @include('partials.inspector.fields.disposition', ['dateModel' => 'vehicle_disposed_on'])
    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

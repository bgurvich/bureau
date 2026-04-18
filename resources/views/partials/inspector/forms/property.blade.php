<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div class="grid grid-cols-2 gap-3">
        <div class="col-span-1">
            <label for="i-pr-kind" class="mb-1 block text-xs text-neutral-400">{{ __('Kind') }}</label>
            <select wire:model="property_kind" id="i-pr-kind" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::propertyKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="i-pr-name" class="mb-1 block text-xs text-neutral-400">{{ __('Name') }}</label>
            <input wire:model="property_name" id="i-pr-name" type="text" required autofocus
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('property_name')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>
    <hr class="border-neutral-800">
    <h4 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Address') }}</h4>
    <input wire:model="property_address_line1" type="text" placeholder="{{ __('Street address') }}"
           aria-label="{{ __('Street address') }}"
           class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    <div class="grid grid-cols-3 gap-2">
        <input wire:model="property_address_city" type="text" placeholder="{{ __('City') }}"
               aria-label="{{ __('City') }}"
               class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <input wire:model="property_address_region" type="text" placeholder="{{ __('Region') }}"
               aria-label="{{ __('Region') }}"
               class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <input wire:model="property_address_postcode" type="text" placeholder="{{ __('Postcode') }}"
               aria-label="{{ __('Postcode') }}"
               class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>
    <hr class="border-neutral-800">
    <h4 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Purchase') }}</h4>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-pr-acq" class="mb-1 block text-xs text-neutral-400">{{ __('Acquired on') }}</label>
            <input wire:model="property_acquired_on" id="i-pr-acq" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-pr-price" class="mb-1 block text-xs text-neutral-400">{{ __('Price') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $property_purchase_currency }}</span>
            </label>
            <input wire:model="property_purchase_price" id="i-pr-price" type="number" step="0.01"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    @include('partials.inspector.fields.disposition', ['dateModel' => 'property_disposed_on'])
    <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
            <label for="i-pr-size" class="mb-1 block text-xs text-neutral-400">{{ __('Size') }}</label>
            <input wire:model="property_size_value" id="i-pr-size" type="number" step="0.01"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-pr-unit" class="mb-1 block text-xs text-neutral-400">{{ __('Unit') }}</label>
            <select wire:model="property_size_unit" id="i-pr-unit"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::propertySizeUnits() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>
    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

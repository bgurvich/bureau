<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div class="grid grid-cols-4 gap-3">
        <div class="col-span-3">
            <label for="i-in-name" class="mb-1 block text-xs text-neutral-400">{{ __('Name') }}</label>
            <input wire:model="inventory_name" id="i-in-name" type="text" required autofocus
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('inventory_name')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-in-qty" class="mb-1 block text-xs text-neutral-400">{{ __('Qty') }}</label>
            <input wire:model="inventory_quantity" id="i-in-qty" type="number" min="1" step="1" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-in-cat" class="mb-1 block text-xs text-neutral-400">{{ __('Category') }}</label>
            <select wire:model="inventory_category" id="i-in-cat"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::inventoryCategories() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="i-in-prop" class="mb-1 block text-xs text-neutral-400">{{ __('Location property') }}</label>
            <select wire:model="inventory_property_id" id="i-in-prop"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">—</option>
                @foreach ($this->propertyOptions as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-in-room" class="mb-1 block text-xs text-neutral-400">{{ __('Room') }}</label>
            <input wire:model="inventory_room" id="i-in-room" type="text" placeholder="{{ __('Kitchen, garage, office…') }}"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-in-container" class="mb-1 block text-xs text-neutral-400">{{ __('Container') }}</label>
            <input wire:model="inventory_container" id="i-in-container" type="text" placeholder="{{ __('Closet 1, travel bag…') }}"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-in-brand" class="mb-1 block text-xs text-neutral-400">{{ __('Brand') }}</label>
            <input wire:model="inventory_brand" id="i-in-brand" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-in-model" class="mb-1 block text-xs text-neutral-400">{{ __('Model #') }}</label>
            <input wire:model="inventory_model_number" id="i-in-model" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div>
        <label for="i-in-serial" class="mb-1 block text-xs text-neutral-400">{{ __('Serial #') }}</label>
        <input wire:model="inventory_serial_number" id="i-in-serial" type="text"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-in-purchased" class="mb-1 block text-xs text-neutral-400">{{ __('Bought on') }}</label>
            <input wire:model="inventory_purchased_on" id="i-in-purchased" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-in-cost" class="mb-1 block text-xs text-neutral-400">{{ __('Cost') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $inventory_cost_currency }}</span>
            </label>
            <input wire:model="inventory_cost_amount" id="i-in-cost" type="number" step="0.01"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div>
        <label for="i-in-vendor" class="mb-1 block text-xs text-neutral-400">{{ __('Vendor') }}</label>
        <x-ui.searchable-select
            id="i-in-vendor"
            model="inventory_vendor_id"
            :options="['' => '—'] + $this->contacts->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
            placeholder="—"
            allow-create
            create-method="createCounterparty" />
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-in-order" class="mb-1 block text-xs text-neutral-400">{{ __('Order #') }}</label>
            <input wire:model="inventory_order_number" id="i-in-order" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-in-returnby" class="mb-1 block text-xs text-neutral-400">{{ __('Return by') }}</label>
            <input wire:model="inventory_return_by" id="i-in-returnby" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div>
        <label for="i-in-warranty" class="mb-1 block text-xs text-neutral-400">{{ __('Warranty expires') }}</label>
        <input wire:model="inventory_warranty_expires_on" id="i-in-warranty" type="date"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>
    <details class="text-xs" @if ($inventory_is_for_sale) open @endif>
        <summary class="cursor-pointer text-sm font-medium text-neutral-200 hover:text-neutral-50">{{ __('For sale') }}</summary>
        <div class="mt-3 space-y-3">
            <label class="flex items-center gap-2 text-sm text-neutral-300">
                <input wire:model.live="inventory_is_for_sale" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
                {{ __('Currently listed') }}
            </label>
            @if ($inventory_is_for_sale)
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label for="i-in-lstplat" class="mb-1 block text-xs text-neutral-400">{{ __('Platform') }}</label>
                        <select wire:model="inventory_listing_platform" id="i-in-lstplat"
                                class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <option value="">—</option>
                            @foreach (App\Support\Enums::inventoryListingPlatforms() as $v => $l)
                                <option value="{{ $v }}">{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="i-in-lstask" class="mb-1 block text-xs text-neutral-400">{{ __('Asking price') }}
                            <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $inventory_listing_asking_currency }}</span>
                        </label>
                        <input wire:model="inventory_listing_asking_amount" id="i-in-lstask" type="number" step="0.01"
                               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    </div>
                    <div>
                        <label for="i-in-lstpost" class="mb-1 block text-xs text-neutral-400">{{ __('Posted') }}</label>
                        <input wire:model="inventory_listing_posted_at" id="i-in-lstpost" type="date"
                               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    </div>
                </div>
                <div>
                    <label for="i-in-lsturl" class="mb-1 block text-xs text-neutral-400">{{ __('Listing URL') }}</label>
                    <input wire:model="inventory_listing_url" id="i-in-lsturl" type="url" inputmode="url"
                           class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 font-mono text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    @error('inventory_listing_url')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
                </div>
            @endif
        </div>
    </details>
    @include('partials.inspector.fields.disposition', ['dateModel' => 'inventory_disposed_on'])
    @include('partials.inspector.fields.photos')
    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

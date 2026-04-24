<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div>
        <label for="i-ls-title" class="mb-1 block text-xs text-neutral-400">{{ __('Title') }}</label>
        <input wire:model="title" id="i-ls-title" type="text" required autofocus
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('title')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ls-platform" class="mb-1 block text-xs text-neutral-400">{{ __('Platform') }}</label>
            <select wire:model="platform" id="i-ls-platform" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (\App\Support\Enums::inventoryListingPlatforms() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="i-ls-status" class="mb-1 block text-xs text-neutral-400">{{ __('Status') }}</label>
            <select wire:model.live="status" id="i-ls-status" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (\App\Support\Enums::listingStatuses() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div>
        <label for="i-ls-item" class="mb-1 block text-xs text-neutral-400">{{ __('Linked inventory item') }}</label>
        <x-ui.searchable-select
            id="i-ls-item"
            model="inventory_item_id"
            :options="['' => '— '.__('none').' —'] + $this->inventoryItems->mapWithKeys(fn ($i) => [$i->id => $i->name])->all()"
            placeholder="{{ __('— none —') }}"
            edit-inspector-type="inventory" />
        <p class="mt-1 text-[11px] text-neutral-500">{{ __('Link to the inventory row this listing represents. Optional — some listings are services or one-offs.') }}</p>
    </div>

    <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
            <label for="i-ls-price" class="mb-1 block text-xs text-neutral-400">{{ __('Price') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $currency }}</span>
            </label>
            <input wire:model="price" id="i-ls-price" type="number" step="0.01" min="0" inputmode="decimal"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-ls-currency" class="mb-1 block text-xs text-neutral-400">{{ __('Currency') }}</label>
            <input wire:model="currency" id="i-ls-currency" type="text" maxlength="3"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm uppercase text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>

    <div>
        <label for="i-ls-url" class="mb-1 block text-xs text-neutral-400">{{ __('External URL') }}</label>
        <input wire:model="external_url" id="i-ls-url" type="url"
               placeholder="https://www.ebay.com/itm/…"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('external_url')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ls-posted" class="mb-1 block text-xs text-neutral-400">{{ __('Posted on') }}</label>
            <input wire:model="posted_on" id="i-ls-posted" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-ls-expires" class="mb-1 block text-xs text-neutral-400">{{ __('Expires on') }}</label>
            <input wire:model="expires_on" id="i-ls-expires" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('expires_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    @if ($status === 'sold')
        <fieldset class="space-y-3 rounded-md border border-neutral-800 bg-neutral-900/40 px-3 py-3">
            <legend class="px-1 text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Sold details') }}</legend>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="i-ls-sold-for" class="mb-1 block text-xs text-neutral-400">{{ __('Sold for') }}
                        <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $currency }}</span>
                    </label>
                    <input wire:model="sold_for" id="i-ls-sold-for" type="number" step="0.01" min="0" inputmode="decimal"
                           class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                </div>
                <div>
                    <label for="i-ls-sold-to" class="mb-1 block text-xs text-neutral-400">{{ __('Sold to') }}</label>
                    <x-ui.searchable-select
                        id="i-ls-sold-to"
                        model="sold_to_contact_id"
                        :options="['' => '— '.__('none').' —'] + $this->contacts->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
                        placeholder="{{ __('— none —') }}"
                        allow-create
                        create-method="createCounterparty"
                        edit-inspector-type="contact" />
                </div>
            </div>
        </fieldset>
    @endif

    <div>
        <label for="i-ls-ended" class="mb-1 block text-xs text-neutral-400">{{ __('Ended on') }}</label>
        <input wire:model="ended_on" id="i-ls-ended" type="date"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <p class="mt-1 text-[11px] text-neutral-500">{{ __('Auto-stamps when status goes to Sold / Expired / Cancelled unless you fill it in.') }}</p>
    </div>

    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

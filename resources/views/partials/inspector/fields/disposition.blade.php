{{-- Collapsed disposition block. Expands when the disposed_on date is set.
     Shared across property/vehicle/inventory.
     Expects: @include(..., ['dateModel' => 'property_disposed_on']) --}}
<details class="text-xs" @if ($this->{$dateModel} || $this->disposition || $this->sale_amount || $this->buyer_contact_id) open @endif>
    <summary class="cursor-pointer text-neutral-500 hover:text-neutral-300">{{ __('Ownership ended') }}</summary>
    <div class="mt-3 space-y-3">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="i-disp-date" class="mb-1 block text-xs text-neutral-400">{{ __('Date') }}</label>
                <input wire:model="{{ $dateModel }}" id="i-disp-date" type="date"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            </div>
            <div>
                <label for="i-disp-how" class="mb-1 block text-xs text-neutral-400">{{ __('How did it end?') }}</label>
                <select wire:model.live="disposition" id="i-disp-how"
                        class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <option value="">—</option>
                    @foreach (App\Support\Enums::assetDispositions() as $v => $l)
                        <option value="{{ $v }}">{{ $l }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        @if (in_array($disposition, ['sold', 'traded'], true))
            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label for="i-disp-amount" class="mb-1 block text-xs text-neutral-400">{{ __('Sale amount') }}
                        <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $sale_currency }}</span>
                    </label>
                    <input wire:model="sale_amount" id="i-disp-amount" type="number" step="0.01"
                           class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                </div>
                <div>
                    <label for="i-disp-ccy" class="mb-1 block text-xs text-neutral-400">{{ __('Currency') }}</label>
                    <input wire:model="sale_currency" id="i-disp-ccy" type="text" maxlength="3"
                           class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm uppercase text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                </div>
            </div>
            <div>
                <label for="i-disp-buyer" class="mb-1 block text-xs text-neutral-400">{{ __('Buyer') }}</label>
                <x-ui.searchable-select
                    id="i-disp-buyer"
                    model="buyer_contact_id"
                    :options="['' => '—'] + $this->contacts->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
                    placeholder="—"
                    allow-create
                    create-method="createCounterparty" />
            </div>
        @endif
    </div>
</details>

<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
            <label for="i-a-name" class="mb-1 block text-xs text-neutral-400">{{ __('Name') }}</label>
            <input wire:model="account_name" id="i-a-name" type="text" required autofocus
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('account_name')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-a-type" class="mb-1 block text-xs text-neutral-400">{{ __('Type') }}</label>
            <select wire:model.live="account_type" id="i-a-type" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::accountTypes() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
            <label for="i-a-open" class="mb-1 block text-xs text-neutral-400">
                @if (in_array($account_type, ['gift_card', 'prepaid'], true))
                    {{ __('Face value') }}
                @else
                    {{ __('Opening balance') }}
                @endif
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $account_currency }}</span>
            </label>
            <input wire:model="account_opening_balance" id="i-a-open" type="number" step="0.01" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('account_opening_balance')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-a-ccy" class="mb-1 block text-xs text-neutral-400">{{ __('Currency') }}</label>
            <input wire:model="account_currency" id="i-a-ccy" type="text" maxlength="3" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm uppercase text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>

    <div>
        <label for="i-a-inst" class="mb-1 block text-xs text-neutral-400">{{ __('Institution') }}</label>
        <input wire:model="account_institution" id="i-a-inst" type="text"
               placeholder="{{ __('Chase, Fidelity, Amazon, Starbucks…') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>
    <div>
        <label for="i-a-vendor" class="mb-1 block text-xs text-neutral-400">{{ __('Vendor') }}</label>
        <x-ui.searchable-select
            id="i-a-vendor"
            model="account_vendor_id"
            :options="['' => '—'] + $this->contacts->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
            placeholder="—"
            allow-create
            create-method="createCounterparty"
            edit-inspector-type="contact" />
        <p class="mt-1 text-[11px] text-neutral-500">{{ __('Who issued this — used mainly for gift cards and prepaid.') }}</p>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-a-mask" class="mb-1 block text-xs text-neutral-400">{{ __('Account # (last 4)') }}</label>
            <input wire:model="account_number_mask" id="i-a-mask" type="text" maxlength="16"
                   placeholder="****1234"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 font-mono text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-a-expires" class="mb-1 block text-xs text-neutral-400">{{ __('Expires on') }}</label>
            <input wire:model="account_expires_on" id="i-a-expires" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-a-opened" class="mb-1 block text-xs text-neutral-400">{{ __('Opened on') }}</label>
            <input wire:model="account_opened_on" id="i-a-opened" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-a-closed" class="mb-1 block text-xs text-neutral-400">{{ __('Closed on') }}</label>
            <input wire:model="account_closed_on" id="i-a-closed" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>

    <div class="flex items-center gap-4">
        <label class="flex items-center gap-2 text-sm text-neutral-200">
            <input wire:model="account_is_active" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Active') }}
        </label>
        <label class="flex items-center gap-2 text-sm text-neutral-200">
            <input wire:model="account_include_in_net_worth" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Count toward net worth') }}
        </label>
    </div>
    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

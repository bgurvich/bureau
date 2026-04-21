<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <p class="rounded-md border border-neutral-800 bg-neutral-900/40 px-3 py-2 text-xs text-neutral-400">
        {{ __('Creates two mirror transactions (outflow + inflow) and a Transfer link. Pick existing transactions below to avoid duplicates if they were already imported.') }}
    </p>

    <div>
        <label for="i-tr-date" class="mb-1 block text-xs text-neutral-400">{{ __('Date') }}</label>
        <input wire:model="transfer_occurred_on" id="i-tr-date" type="date" required autofocus
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('transfer_occurred_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-tr-from" class="mb-1 block text-xs text-neutral-400">{{ __('From account') }}</label>
            <x-ui.searchable-select
                id="i-tr-from"
                model="transfer_from_account_id"
                :options="['' => '—'] + $this->accounts->mapWithKeys(fn ($a) => [$a->id => $a->name.' · '.$a->currency])->all()"
                placeholder="—" />
            @error('transfer_from_account_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-tr-to" class="mb-1 block text-xs text-neutral-400">{{ __('To account') }}</label>
            <x-ui.searchable-select
                id="i-tr-to"
                model="transfer_to_account_id"
                :options="['' => '—'] + $this->accounts->mapWithKeys(fn ($a) => [$a->id => $a->name.' · '.$a->currency])->all()"
                placeholder="—" />
            @error('transfer_to_account_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
            <label for="i-tr-amt" class="mb-1 block text-xs text-neutral-400">{{ __('Amount') }}</label>
            <input wire:model="transfer_amount" id="i-tr-amt" type="number" step="0.01" min="0.01" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('transfer_amount')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-tr-cur" class="mb-1 block text-xs text-neutral-400">{{ __('Currency') }}</label>
            <input wire:model="transfer_currency" id="i-tr-cur" type="text" maxlength="3" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm uppercase text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('transfer_currency')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div>
        <label for="i-tr-desc" class="mb-1 block text-xs text-neutral-400">{{ __('Description') }}</label>
        <input wire:model="transfer_description" id="i-tr-desc" type="text" maxlength="500"
               placeholder="{{ __('Transfer') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('transfer_description')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <div class="rounded-md border border-dashed border-neutral-800 bg-neutral-900/20 p-3 space-y-3">
        <h4 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">
            {{ __('Link existing transactions (optional)') }}
        </h4>
        <p class="text-[11px] text-neutral-500">
            {{ __('If one or both sides of this transfer were already imported, pick them here. An empty picker means the mirror transaction will be created.') }}
        </p>

        <div>
            <label for="i-tr-from-txn" class="mb-1 block text-xs text-neutral-400">{{ __('Outflow transaction') }}</label>
            <x-ui.searchable-select
                id="i-tr-from-txn"
                model="transfer_from_transaction_id"
                :options="['' => __('Create new').'…'] + $this->transferOutflowPickerOptions"
                placeholder="—" />
            @error('transfer_from_transaction_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>

        <div>
            <label for="i-tr-to-txn" class="mb-1 block text-xs text-neutral-400">{{ __('Inflow transaction') }}</label>
            <x-ui.searchable-select
                id="i-tr-to-txn"
                model="transfer_to_transaction_id"
                :options="['' => __('Create new').'…'] + $this->transferInflowPickerOptions"
                placeholder="—" />
            @error('transfer_to_transaction_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>
</form>

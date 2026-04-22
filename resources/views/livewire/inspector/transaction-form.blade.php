<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    @if ($errorMessage)
        <div role="alert" class="rounded-md border border-rose-700 bg-rose-950/60 px-3 py-2 text-sm text-rose-100">
            {{ $errorMessage }}
        </div>
    @endif
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-txn-date" class="mb-1 block text-xs text-neutral-400">{{ __('Date') }}</label>
            <input wire:model="occurred_on" id="i-txn-date" type="date" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('occurred_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-txn-status" class="mb-1 block text-xs text-neutral-400">{{ __('Status') }}</label>
            <select wire:model="status" id="i-txn-status"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::transactionStatuses() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div>
        <label for="i-txn-acct" class="mb-1 block text-xs text-neutral-400">{{ __('Account') }}</label>
        <select wire:model.live="account_id" id="i-txn-acct" required
                class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <option value="">—</option>
            @foreach ($this->accounts as $a)
                <option value="{{ $a->id }}">{{ $a->name }}</option>
            @endforeach
        </select>
        @error('account_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>
    <div>
        <label for="i-txn-amount" class="mb-1 block text-xs text-neutral-400">{{ __('Amount') }}
            <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $currency }}</span>
        </label>
        <input wire:model="amount" id="i-txn-amount" type="number" step="0.01" required
               placeholder="{{ __('Negative = out, positive = in') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('amount')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>
    <div>
        <label for="i-txn-desc" class="mb-1 block text-xs text-neutral-400">{{ __('Description') }}</label>
        <textarea wire:model="description" id="i-txn-desc" rows="2"
                  class="w-full resize-y rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>
    <div>
        <label for="i-txn-memo" class="mb-1 block text-xs text-neutral-400">{{ __('Bank memo') }}</label>
        <input wire:model="memo" id="i-txn-memo" type="text"
               placeholder="{{ __('As it appears on the statement') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-txn-cat" class="mb-1 block text-xs text-neutral-400">{{ __('Category') }}</label>
            <x-ui.searchable-select
                id="i-txn-cat"
                model="category_id"
                :options="['' => '—'] + $this->categories->mapWithKeys(fn ($c) => [$c->id => $c->displayLabel(includeKind: true)])->all()"
                :allow-create="true"
                create-method="createCategoryInline"
                placeholder="—" />
        </div>
        <div>
            <label for="i-txn-cp" class="mb-1 block text-xs text-neutral-400">{{ __('Counterparty') }}</label>
            <x-ui.searchable-select
                id="i-txn-cp"
                model="counterparty_contact_id"
                :options="['' => '—'] + $this->contacts->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
                placeholder="—"
                allow-create
                create-method="createCounterparty"
                edit-inspector-type="contact" />
        </div>
    </div>
    <div class="grid grid-cols-3 gap-3">
        <div>
            <label for="i-txn-ref" class="mb-1 block text-xs text-neutral-400">{{ __('Reference') }}</label>
            <input wire:model="reference_number" id="i-txn-ref" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-txn-tax" class="mb-1 block text-xs text-neutral-400">{{ __('Tax amount') }}</label>
            <input wire:model="tax_amount" id="i-txn-tax" type="number" step="0.01"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-txn-txc" class="mb-1 block text-xs text-neutral-400">{{ __('Tax code') }}</label>
            <input wire:model="tax_code" id="i-txn-txc" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    @include('partials.inspector.fields.subjects')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

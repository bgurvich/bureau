<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div>
        <label for="i-bill-title" class="mb-1 block text-xs text-neutral-400">{{ __('Title') }}</label>
        <input wire:model="bill_title" id="i-bill-title" type="text" required autofocus
               placeholder="{{ __('Water utility, Rent, Medical visit…') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('bill_title')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-bill-issued" class="mb-1 block text-xs text-neutral-400">{{ __('Issue date') }}</label>
            <input wire:model="issued_on" id="i-bill-issued" type="date" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('issued_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-bill-due" class="mb-1 block text-xs text-neutral-400">{{ __('Due date') }}</label>
            <input wire:model="due_on" id="i-bill-due" type="date" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('due_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div>
        <label for="i-bill-amount" class="mb-1 block text-xs text-neutral-400">{{ __('Amount') }}
            <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $currency }}</span>
        </label>
        <input wire:model="amount" id="i-bill-amount" type="number" step="0.01" required
               placeholder="{{ __('Negative = out, positive = in') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('amount')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <div>
        <label for="i-bill-acct" class="mb-1 block text-xs text-neutral-400">{{ __('Payment account') }}</label>
        <select wire:model.live="account_id" id="i-bill-acct" required
                class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <option value="">—</option>
            @foreach ($this->accounts as $a)
                <option value="{{ $a->id }}">{{ $a->name }}</option>
            @endforeach
        </select>
        @error('account_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-bill-cat" class="mb-1 block text-xs text-neutral-400">{{ __('Category') }}</label>
            <x-ui.searchable-select
                id="i-bill-cat"
                model="category_id"
                :options="['' => '—'] + $this->categories->mapWithKeys(fn ($c) => [$c->id => ucfirst($c->kind).' · '.$c->name])->all()"
                placeholder="—" />
        </div>
        <div>
            <label for="i-bill-cp" class="mb-1 block text-xs text-neutral-400">{{ __('Counterparty') }}</label>
            <x-ui.searchable-select
                id="i-bill-cp"
                model="counterparty_contact_id"
                :options="['' => '—'] + $this->contacts->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
                placeholder="—"
                allow-create
                create-method="createCounterparty" />
        </div>
    </div>

    <hr class="border-neutral-800">
    <h4 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Schedule') }}</h4>
    <label class="flex items-center gap-2 text-sm text-neutral-200">
        <input wire:model.live="is_recurring" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
        {{ __('Recurring bill') }}
    </label>
    @if ($is_recurring)
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="i-bill-freq" class="mb-1 block text-xs text-neutral-400">{{ __('Repeats') }}</label>
                <select wire:model="frequency" id="i-bill-freq"
                        class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    @foreach (App\Support\Enums::billFrequencies() as $v => $l)
                        <option value="{{ $v }}">{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="i-bill-until" class="mb-1 block text-xs text-neutral-400">{{ __('Stop after') }}</label>
                <input wire:model="bill_until" id="i-bill-until" type="date"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <p class="mt-1 text-[11px] text-neutral-500">{{ __('Leave blank for no end.') }}</p>
            </div>
        </div>
    @endif
    <div class="grid grid-cols-2 gap-3">
        <label class="flex items-center gap-2 text-sm text-neutral-200">
            <input wire:model="autopay" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            <span>{{ __('Auto-pay (bank charges automatically)') }}</span>
        </label>
        <div>
            <label for="i-bill-lead" class="mb-1 block text-xs text-neutral-400">{{ __('Remind :d days before', ['d' => $bill_lead_days]) }}</label>
            <input wire:model="bill_lead_days" id="i-bill-lead" type="number" min="0" max="365" step="1"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

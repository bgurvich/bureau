<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div>
        <label for="i-k-title" class="mb-1 block text-xs text-neutral-400">{{ __('Title') }}</label>
        <input wire:model="contract_title" id="i-k-title" type="text" required autofocus
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('contract_title')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-k-kind" class="mb-1 block text-xs text-neutral-400">{{ __('Kind') }}</label>
            <select wire:model="contract_kind" id="i-k-kind" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::contractKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="i-k-state" class="mb-1 block text-xs text-neutral-400">{{ __('State') }}</label>
            <select wire:model="contract_state" id="i-k-state" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::contractStates() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div>
        <label for="i-k-cp" class="mb-1 block text-xs text-neutral-400">{{ __('Counterparty') }}</label>
        <x-ui.searchable-select
            id="i-k-cp"
            model="contract_counterparty_id"
            :options="['' => '—'] + $this->contacts->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
            placeholder="—"
            allow-create
            create-method="createCounterparty" />
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-k-starts" class="mb-1 block text-xs text-neutral-400">{{ __('Starts on') }}</label>
            <input wire:model="contract_starts_on" id="i-k-starts" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-k-ends" class="mb-1 block text-xs text-neutral-400">{{ __('Ends on') }}</label>
            <input wire:model="contract_ends_on" id="i-k-ends" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div>
        <label for="i-k-cost" class="mb-1 block text-xs text-neutral-400">{{ __('Monthly cost') }}
            <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $contract_monthly_cost_currency }}</span>
        </label>
        <input wire:model="contract_monthly_cost" id="i-k-cost" type="number" step="0.01"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>
    <div class="grid grid-cols-2 gap-3">
        <label class="flex items-center gap-2 text-sm text-neutral-200">
            <input wire:model="contract_auto_renews" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Auto-renews') }}
        </label>
        <div>
            <label for="i-k-notice" class="mb-1 block text-xs text-neutral-400">{{ __('Cancel-by notice (days before end)') }}</label>
            <input wire:model="contract_renewal_notice_days" id="i-k-notice" type="number" min="0" max="365" step="1"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div>
        <label for="i-k-trial" class="mb-1 block text-xs text-neutral-400">{{ __('Cancel trial by') }}</label>
        <input wire:model="contract_trial_ends_on" id="i-k-trial" type="date"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <p class="mt-1 text-[11px] text-neutral-500">{{ __('Free-trial window; leave blank if this is already a paid contract.') }}</p>
    </div>
    <fieldset class="space-y-3 rounded-md border border-neutral-800 bg-neutral-900/40 px-3 py-3">
        <legend class="px-1 text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('How to cancel') }}</legend>
        <div>
            <label for="i-k-cancel-url" class="mb-1 block text-xs text-neutral-400">{{ __('Cancellation URL') }}</label>
            <input wire:model="contract_cancellation_url" id="i-k-cancel-url" type="url"
                   placeholder="https://example.com/account/cancel"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('contract_cancellation_url')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-k-cancel-email" class="mb-1 block text-xs text-neutral-400">{{ __('Cancellation email') }}</label>
            <input wire:model="contract_cancellation_email" id="i-k-cancel-email" type="email"
                   placeholder="unsubscribe@example.com"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('contract_cancellation_email')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <p class="text-[11px] text-neutral-500">{{ __('Drop the link (or email) here so future-you doesn\'t have to hunt for it when cancelling.') }}</p>
    </fieldset>
    @include('partials.inspector.fields.photos')
    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

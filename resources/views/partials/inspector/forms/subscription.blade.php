<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div>
        <label for="i-sub-name" class="mb-1 block text-xs text-neutral-400">{{ __('Name') }}</label>
        <input wire:model="subscription_name" id="i-sub-name" type="text" required autofocus
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('subscription_name')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>
    <div>
        <label for="i-sub-counter" class="mb-1 block text-xs text-neutral-400">{{ __('Counterparty (vendor)') }}</label>
        <x-ui.searchable-select
            id="i-sub-counter"
            model="subscription_counterparty_id"
            :options="['' => '— '.__('none').' —'] + $this->counterpartyPickerOptions"
            placeholder="{{ __('— none —') }}" />
    </div>
    <div>
        <label for="i-sub-rule" class="mb-1 block text-xs text-neutral-400">{{ __('Recurring rule (money side)') }}</label>
        <x-ui.searchable-select
            id="i-sub-rule"
            model="subscription_recurring_rule_id"
            :options="['' => '— '.__('none').' —'] + $this->recurringOutflowRulePickerOptions"
            placeholder="{{ __('— none —') }}" />
        <p class="mt-1 text-[11px] text-neutral-500">{{ __('New subscriptions auto-create one when you save a matching recurring rule. You can also link an existing rule here.') }}</p>
    </div>
    <div>
        <label for="i-sub-contract" class="mb-1 block text-xs text-neutral-400">{{ __('Contract (cancellation side)') }}</label>
        <x-ui.searchable-select
            id="i-sub-contract"
            model="subscription_contract_id"
            :options="['' => '— '.__('none').' —'] + $this->openContractPickerOptions"
            placeholder="{{ __('— none —') }}" />
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-sub-state" class="mb-1 block text-xs text-neutral-400">{{ __('State') }}</label>
            <select wire:model.live="subscription_state" id="i-sub-state"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="active">{{ __('active') }}</option>
                <option value="paused">{{ __('paused') }}</option>
                <option value="cancelled">{{ __('cancelled') }}</option>
            </select>
        </div>
        @if ($subscription_state === 'paused')
            <div>
                <label for="i-sub-until" class="mb-1 block text-xs text-neutral-400">{{ __('Resume on') }}</label>
                <input wire:model="subscription_paused_until" id="i-sub-until" type="date"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <p class="mt-1 text-[11px] text-neutral-500">{{ __('Optional. Nightly cron flips to active when this date arrives.') }}</p>
            </div>
        @endif
    </div>
    <div>
        <label for="i-sub-notes" class="mb-1 block text-xs text-neutral-400">{{ __('Notes') }}</label>
        <textarea wire:model="subscription_notes" id="i-sub-notes" rows="3"
                  class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>
    @include('partials.inspector.fields.admin')
</form>

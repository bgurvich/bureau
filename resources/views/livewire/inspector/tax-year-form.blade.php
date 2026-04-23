<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ty-year" class="mb-1 block text-xs text-neutral-400">{{ __('Tax year') }}</label>
            <input wire:model="year" id="i-ty-year" type="number" min="1900" max="2100" required autofocus
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('year')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-ty-jur" class="mb-1 block text-xs text-neutral-400">{{ __('Jurisdiction') }}</label>
            <input wire:model="jurisdiction" id="i-ty-jur" type="text" required
                   placeholder="US-federal, US-CA, …"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ty-state" class="mb-1 block text-xs text-neutral-400">{{ __('State') }}</label>
            <select wire:model="state" id="i-ty-state"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::taxYearStates() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="i-ty-status" class="mb-1 block text-xs text-neutral-400">{{ __('Filing status') }}</label>
            <select wire:model="filing_status" id="i-ty-status"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">—</option>
                @foreach (App\Support\Enums::taxFilingStatuses() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ty-preparer" class="mb-1 block text-xs text-neutral-400">{{ __('Preparer') }}</label>
            <x-ui.searchable-select
                id="i-ty-preparer"
                model="preparer_contact_id"
                :options="['' => '— '.__('self').' —'] + $this->contacts->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
                placeholder="{{ __('— self —') }}"
                allow-create
                create-method="createCounterparty"
                edit-inspector-type="contact" />
            <p class="mt-1 text-[11px] text-neutral-500">{{ __('Whoever put the numbers on the return — CPA, spouse, self.') }}</p>
        </div>
        <div>
            <label for="i-ty-bookkeeper" class="mb-1 block text-xs text-neutral-400">{{ __('Bookkeeper') }}</label>
            <x-ui.searchable-select
                id="i-ty-bookkeeper"
                model="bookkeeper_contact_id"
                :options="['' => '— '.__('none').' —'] + $this->contacts->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
                placeholder="{{ __('— none —') }}"
                allow-create
                create-method="createCounterparty"
                edit-inspector-type="contact" />
            <p class="mt-1 text-[11px] text-neutral-500">{{ __('Who categorized the underlying transactions through the year.') }}</p>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ty-filed" class="mb-1 block text-xs text-neutral-400">{{ __('Filed on') }}</label>
            <input wire:model="filed_on" id="i-ty-filed" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <p class="mt-1 text-[11px] text-neutral-500">{{ __('Leave blank until the return is submitted.') }}</p>
        </div>
        <div>
            <label for="i-ty-settlement" class="mb-1 block text-xs text-neutral-400">{{ __('Settlement') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $currency }}</span>
            </label>
            <input wire:model="settlement_amount" id="i-ty-settlement" type="number" step="0.01"
                   placeholder="{{ __('+ refund / − owed') }}"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>

    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

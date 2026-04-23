<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-td-year" class="mb-1 block text-xs text-neutral-400">{{ __('Tax year') }}</label>
            <select wire:model="tax_year_id" id="i-td-year" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">—</option>
                @foreach ($this->taxYears as $ty)
                    <option value="{{ $ty->id }}">{{ $ty->year }} · {{ $ty->jurisdiction }}</option>
                @endforeach
            </select>
            @error('tax_year_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-td-kind" class="mb-1 block text-xs text-neutral-400">{{ __('Kind') }}</label>
            <select wire:model="kind" id="i-td-kind" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::taxDocumentKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div>
        <label for="i-td-label" class="mb-1 block text-xs text-neutral-400">{{ __('Label') }}</label>
        <input wire:model="label" id="i-td-label" type="text"
               placeholder="{{ __('Optional — e.g. “Fidelity brokerage” to distinguish two 1099-Bs') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>

    <div>
        <label for="i-td-from" class="mb-1 block text-xs text-neutral-400">{{ __('From (issuer)') }}</label>
        <x-ui.searchable-select
            id="i-td-from"
            model="from_contact_id"
            :options="['' => '—'] + $this->contacts->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
            placeholder="—"
            allow-create
            create-method="createCounterparty"
            edit-inspector-type="contact" />
        <p class="mt-1 text-[11px] text-neutral-500">{{ __('Employer, bank, broker, or partnership.') }}</p>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-td-received" class="mb-1 block text-xs text-neutral-400">{{ __('Received on') }}</label>
            <input wire:model="received_on" id="i-td-received" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-td-amount" class="mb-1 block text-xs text-neutral-400">{{ __('Amount') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $currency }}</span>
            </label>
            <input wire:model="amount" id="i-td-amount" type="number" step="0.01"
                   placeholder="{{ __('Box 1 / total') }}"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>

    @include('partials.inspector.fields.notes')
</form>

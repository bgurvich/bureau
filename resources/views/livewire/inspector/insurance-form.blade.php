<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div>
        <label for="i-ins-title" class="mb-1 block text-xs text-neutral-400">{{ __('Title') }}</label>
        <input wire:model="insurance_title" id="i-ins-title" type="text" required autofocus
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('insurance_title')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ins-cov" class="mb-1 block text-xs text-neutral-400">{{ __('Coverage') }}</label>
            <select wire:model="insurance_coverage_kind" id="i-ins-cov" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::insuranceCoverageKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="i-ins-num" class="mb-1 block text-xs text-neutral-400">{{ __('Policy #') }}</label>
            <input wire:model="insurance_policy_number" id="i-ins-num" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div>
        <label for="i-ins-carrier" class="mb-1 block text-xs text-neutral-400">{{ __('Carrier') }}</label>
        <x-ui.searchable-select
            id="i-ins-carrier"
            model="insurance_carrier_id"
            :options="['' => '—'] + $this->contacts->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
            placeholder="—"
            allow-create
            create-method="createCounterparty"
            edit-inspector-type="contact" />
    </div>
    <div>
        <label for="i-ins-subj" class="mb-1 block text-xs text-neutral-400">{{ __('Covered subject') }}</label>
        <x-ui.searchable-select
            id="i-ins-subj"
            model="insurance_subject"
            :options="['' => '—'] + $this->insuranceSubjectOptions"
            placeholder="—" />
        <p class="mt-1 text-[11px] text-neutral-500">{{ __('Vehicle, property, or person covered by this policy.') }}</p>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ins-starts" class="mb-1 block text-xs text-neutral-400">{{ __('Starts on') }}</label>
            <input wire:model="insurance_starts_on" id="i-ins-starts" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-ins-ends" class="mb-1 block text-xs text-neutral-400">{{ __('Ends on') }}</label>
            <input wire:model="insurance_ends_on" id="i-ins-ends" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <label class="flex items-center gap-2 text-sm text-neutral-200">
        <input wire:model="insurance_auto_renews" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
        {{ __('Auto-renews') }}
    </label>

    <hr class="border-neutral-800">
    <h4 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Premium') }}</h4>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ins-pa" class="mb-1 block text-xs text-neutral-400">{{ __('Amount') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $insurance_premium_currency }}</span>
            </label>
            <input wire:model="insurance_premium_amount" id="i-ins-pa" type="number" step="0.01"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-ins-pd" class="mb-1 block text-xs text-neutral-400">{{ __('Cadence') }}</label>
            <select wire:model="insurance_premium_cadence" id="i-ins-pd" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::insurancePremiumCadences() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <hr class="border-neutral-800">
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ins-ca" class="mb-1 block text-xs text-neutral-400">{{ __('Coverage amount') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $insurance_coverage_currency }}</span>
            </label>
            <input wire:model="insurance_coverage_amount" id="i-ins-ca" type="number" step="0.01"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-ins-da" class="mb-1 block text-xs text-neutral-400">{{ __('Deductible') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $insurance_deductible_currency }}</span>
            </label>
            <input wire:model="insurance_deductible_amount" id="i-ins-da" type="number" step="0.01"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>

    <div>
        <label for="i-ins-notes" class="mb-1 block text-xs text-neutral-400">{{ __('Notes') }}</label>
        <textarea wire:model="insurance_notes" id="i-ins-notes" rows="3"
                  class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

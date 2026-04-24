<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ppc-pet" class="mb-1 block text-xs text-neutral-400">{{ __('Pet') }}</label>
            <select wire:model="pet_id" id="i-ppc-pet" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">—</option>
                @foreach ($this->pets as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                @endforeach
            </select>
            @error('pet_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-ppc-kind" class="mb-1 block text-xs text-neutral-400">{{ __('Kind') }}</label>
            <select wire:model.live="kind" id="i-ppc-kind" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (\App\Support\Enums::petPreventiveCareKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div>
        <label for="i-ppc-label" class="mb-1 block text-xs text-neutral-400">{{ __('Product / label') }}</label>
        <input wire:model="label" id="i-ppc-label" type="text"
               placeholder="{{ __('Heartgard Plus, Bravecto, …') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <p class="mt-1 text-[11px] text-neutral-500">{{ __('Brand or product name — optional, useful when switching meds.') }}</p>
    </div>

    <div class="grid grid-cols-3 gap-3">
        <div>
            <label for="i-ppc-applied" class="mb-1 block text-xs text-neutral-400">{{ __('Applied on') }}</label>
            <input wire:model.live="applied_on" id="i-ppc-applied" type="date" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('applied_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-ppc-interval" class="mb-1 block text-xs text-neutral-400">{{ __('Interval (days)') }}</label>
            <input wire:model.live="interval_days" id="i-ppc-interval" type="number" min="1" max="3650" step="1" inputmode="numeric"
                   placeholder="{{ __('e.g. 30') }}"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <p class="mt-1 text-[11px] text-neutral-500">{{ __('Blank = one-off.') }}</p>
        </div>
        <div>
            <label for="i-ppc-next" class="mb-1 block text-xs text-neutral-400">{{ __('Next due') }}</label>
            <input wire:model="next_due_on" id="i-ppc-next" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('next_due_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
            <label for="i-ppc-cost" class="mb-1 block text-xs text-neutral-400">{{ __('Cost') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $currency }}</span>
            </label>
            <input wire:model="cost" id="i-ppc-cost" type="number" step="0.01" min="0" inputmode="decimal"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-ppc-currency" class="mb-1 block text-xs text-neutral-400">{{ __('Currency') }}</label>
            <input wire:model="currency" id="i-ppc-currency" type="text" maxlength="3"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm uppercase text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>

    <div>
        <label for="i-ppc-provider" class="mb-1 block text-xs text-neutral-400">{{ __('Provider') }}</label>
        <select wire:model="provider_id" id="i-ppc-provider"
                class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <option value="">— {{ __('none') }} —</option>
            @foreach ($this->providers as $p)
                <option value="{{ $p->id }}">{{ $p->name }}</option>
            @endforeach
        </select>
    </div>

    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

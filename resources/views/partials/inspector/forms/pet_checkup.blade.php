<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    @php($pet = $pc_pet_id ? \App\Models\Pet::find($pc_pet_id) : null)
    <div class="rounded-md border border-neutral-800 bg-neutral-900/40 px-3 py-2 text-[11px] text-neutral-400">
        {{ __('Pet') }}:
        <span class="font-medium text-neutral-200">{{ $pet?->name ?? __('(none)') }}</span>
        @if ($pet?->species)
            <span class="ml-1 text-neutral-500">· {{ ucfirst($pet->species) }}</span>
        @endif
    </div>
    @error('pc_pet_id')<div role="alert" class="text-xs text-rose-400">{{ $message }}</div>@enderror

    <div>
        <label for="i-pc-kind" class="mb-1 block text-xs text-neutral-400">{{ __('Kind') }}</label>
        <select wire:model="pc_kind" id="i-pc-kind"
                class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <option value="annual_checkup">{{ __('Annual checkup') }}</option>
            <option value="dental_cleaning">{{ __('Dental cleaning') }}</option>
            <option value="teeth_cleaning">{{ __('Teeth cleaning (home)') }}</option>
            <option value="grooming">{{ __('Grooming') }}</option>
            <option value="nail_trim">{{ __('Nail trim') }}</option>
            <option value="blood_panel">{{ __('Blood panel') }}</option>
            <option value="other">{{ __('Other') }}</option>
        </select>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-pc-on" class="mb-1 block text-xs text-neutral-400">{{ __('Checkup on') }}</label>
            <input wire:model="pc_checkup_on" id="i-pc-on" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-pc-due" class="mb-1 block text-xs text-neutral-400">{{ __('Next due on') }}</label>
            <input wire:model="pc_next_due_on" id="i-pc-due" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('pc_next_due_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-pc-cost" class="mb-1 block text-xs text-neutral-400">{{ __('Cost') }}</label>
            <input wire:model="pc_cost" id="i-pc-cost" type="number" step="0.01" min="0"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-pc-curr" class="mb-1 block text-xs text-neutral-400">{{ __('Currency') }}</label>
            <input wire:model="pc_currency" id="i-pc-curr" type="text" maxlength="3"
                   placeholder="USD"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm uppercase text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>

    <div>
        <label for="i-pc-find" class="mb-1 block text-xs text-neutral-400">{{ __('Findings') }}</label>
        <textarea wire:model="pc_findings" id="i-pc-find" rows="3"
                  class="w-full resize-y rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>
</form>

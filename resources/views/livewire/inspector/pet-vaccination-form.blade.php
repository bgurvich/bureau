<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    @php($pet = $pet_id ? \App\Models\Pet::find($pet_id) : null)
    <div class="rounded-md border border-neutral-800 bg-neutral-900/40 px-3 py-2 text-[11px] text-neutral-400">
        {{ __('Pet') }}:
        <span class="font-medium text-neutral-200">{{ $pet?->name ?? __('(none)') }}</span>
        @if ($pet?->species)
            <span class="ml-1 text-neutral-500">· {{ ucfirst($pet->species) }}</span>
        @endif
    </div>
    @error('pet_id')<div role="alert" class="text-xs text-rose-400">{{ $message }}</div>@enderror

    <div>
        <label for="i-pv-name" class="mb-1 block text-xs text-neutral-400">{{ __('Vaccine') }}</label>
        @php($templates = $pet ? \App\Support\PetVaccineTemplates::forSpecies((string) $pet->species) : [])
        <input wire:model="vaccine_name" id="i-pv-name" type="text" required autofocus
               list="i-pv-name-opts"
               placeholder="{{ __('Rabies, DHPP, ...') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @if ($templates !== [])
            <datalist id="i-pv-name-opts">
                @foreach ($templates as $t)
                    <option value="{{ $t['name'] }}">{{ $t['notes'] }}</option>
                @endforeach
            </datalist>
        @endif
        @error('vaccine_name')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-pv-admin" class="mb-1 block text-xs text-neutral-400">{{ __('Administered on') }}</label>
            <input wire:model="administered_on" id="i-pv-admin" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-pv-valid" class="mb-1 block text-xs text-neutral-400">{{ __('Valid until') }}</label>
            <input wire:model="valid_until" id="i-pv-valid" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('valid_until')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div>
        <label for="i-pv-booster" class="mb-1 block text-xs text-neutral-400">{{ __('Booster due on') }}</label>
        <input wire:model="booster_due_on" id="i-pv-booster" type="date"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <p class="mt-1 text-[11px] text-neutral-500">
            {{ __('Optional — some protocols schedule a booster before the previous dose\'s validity runs out.') }}
        </p>
    </div>

    <div>
        <label for="i-pv-notes" class="mb-1 block text-xs text-neutral-400">{{ __('Notes') }}</label>
        <textarea wire:model="notes" id="i-pv-notes" rows="2"
                  class="w-full resize-y rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>
</form>

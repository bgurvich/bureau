<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-pet-species" class="mb-1 block text-xs text-neutral-400">{{ __('Species') }}</label>
            <select wire:model="pet_species" id="i-pet-species"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="dog">{{ __('Dog') }}</option>
                <option value="cat">{{ __('Cat') }}</option>
                <option value="rabbit">{{ __('Rabbit') }}</option>
                <option value="ferret">{{ __('Ferret') }}</option>
                <option value="other">{{ __('Other') }}</option>
            </select>
            @if (! $id)
                <p class="mt-1 text-[11px] text-neutral-500">
                    {{ __('Required vaccines for this species will be seeded on save — you fill in dates as records come back from the vet.') }}
                </p>
            @endif
        </div>
        <div>
            <label for="i-pet-name" class="mb-1 block text-xs text-neutral-400">{{ __('Name') }}</label>
            <input wire:model="pet_name" id="i-pet-name" type="text" required autofocus
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('pet_name')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-pet-breed" class="mb-1 block text-xs text-neutral-400">{{ __('Breed') }}</label>
            <input wire:model="pet_breed" id="i-pet-breed" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-pet-color" class="mb-1 block text-xs text-neutral-400">{{ __('Color') }}</label>
            <input wire:model="pet_color" id="i-pet-color" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-pet-dob" class="mb-1 block text-xs text-neutral-400">{{ __('Date of birth') }}</label>
            <input wire:model="pet_date_of_birth" id="i-pet-dob" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-pet-sex" class="mb-1 block text-xs text-neutral-400">{{ __('Sex') }}</label>
            <select wire:model="pet_sex" id="i-pet-sex"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">—</option>
                <option value="male">{{ __('Male') }}</option>
                <option value="female">{{ __('Female') }}</option>
                <option value="unknown">{{ __('Unknown') }}</option>
            </select>
        </div>
    </div>

    <div>
        <label for="i-pet-microchip" class="mb-1 block text-xs text-neutral-400">{{ __('Microchip ID') }}</label>
        <input wire:model="pet_microchip_id" id="i-pet-microchip" type="text"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>

    <label class="flex items-center gap-2 text-xs text-neutral-300">
        <input wire:model="pet_is_active" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
        {{ __('Active') }}
        <span class="text-neutral-500">{{ __('(uncheck after the pet passes to keep records without clutter)') }}</span>
    </label>

    <div>
        <label for="i-pet-notes" class="mb-1 block text-xs text-neutral-400">{{ __('Notes') }}</label>
        <textarea wire:model="pet_notes" id="i-pet-notes" rows="3"
                  class="w-full resize-y rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>

    @if ($id)
        {{-- Vaccinations + checkups open as modal sub-entity editors so
             the parent pet form's in-flight state isn't lost. --}}
        @php(
            $pet = \App\Models\Pet::with([
                'vaccinations' => fn ($q) => $q->orderByRaw('valid_until IS NULL, valid_until ASC')->orderBy('vaccine_name'),
                'checkups' => fn ($q) => $q->orderByRaw('next_due_on IS NULL, next_due_on ASC'),
            ])->find($id)
        )
        @php($today = \Carbon\CarbonImmutable::today())

        <fieldset class="rounded-lg border border-neutral-800 p-3">
            <legend class="flex items-center gap-2 px-1 text-xs text-neutral-400">
                <span>{{ __('Vaccinations') }}</span>
                <button type="button"
                        wire:click.stop="$dispatch('subentity-edit-open', { type: 'pet_vaccination', parentId: {{ $id }} })"
                        class="rounded-md border border-neutral-700 bg-neutral-900 px-2 py-0.5 text-[10px] uppercase tracking-wider text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('+ Add') }}
                </button>
            </legend>
            @if ($pet && $pet->vaccinations->isNotEmpty())
                <ul class="mt-2 space-y-1 text-xs">
                    @foreach ($pet->vaccinations as $v)
                        @php($expired = $v->valid_until && $v->valid_until->lessThan($today))
                        @php($placeholder = $v->administered_on === null)
                        <li>
                            <button type="button"
                                    wire:click.stop="$dispatch('subentity-edit-open', { type: 'pet_vaccination', id: {{ $v->id }} })"
                                    class="flex w-full items-baseline justify-between gap-3 rounded px-2 py-1 text-left hover:bg-neutral-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                <span class="truncate">
                                    {{ $v->vaccine_name }}
                                    @if ($placeholder)
                                        <span class="ml-1 text-[10px] uppercase tracking-wider text-neutral-500">{{ __('placeholder') }}</span>
                                    @endif
                                </span>
                                <span class="shrink-0 tabular-nums {{ $expired ? 'text-rose-300' : 'text-neutral-500' }}">
                                    @if ($v->valid_until)
                                        {{ $expired ? __('expired') : __('valid through') }} {{ \App\Support\Formatting::date($v->valid_until) }}
                                    @elseif ($v->administered_on)
                                        {{ __('done') }} {{ \App\Support\Formatting::date($v->administered_on) }}
                                    @else
                                        —
                                    @endif
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-2 text-[11px] text-neutral-500">{{ __('No vaccinations recorded yet.') }}</p>
            @endif
        </fieldset>

        <fieldset class="rounded-lg border border-neutral-800 p-3">
            <legend class="flex items-center gap-2 px-1 text-xs text-neutral-400">
                <span>{{ __('Checkups') }}</span>
                <button type="button"
                        wire:click.stop="$dispatch('subentity-edit-open', { type: 'pet_checkup', parentId: {{ $id }} })"
                        class="rounded-md border border-neutral-700 bg-neutral-900 px-2 py-0.5 text-[10px] uppercase tracking-wider text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('+ Add') }}
                </button>
            </legend>
            @if ($pet && $pet->checkups->isNotEmpty())
                <ul class="mt-2 space-y-1 text-xs">
                    @foreach ($pet->checkups as $c)
                        @php($overdue = $c->next_due_on && $c->next_due_on->lessThan($today))
                        <li>
                            <button type="button"
                                    wire:click.stop="$dispatch('subentity-edit-open', { type: 'pet_checkup', id: {{ $c->id }} })"
                                    class="flex w-full items-baseline justify-between gap-3 rounded px-2 py-1 text-left hover:bg-neutral-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                <span class="truncate">
                                    {{ str_replace('_', ' ', ucfirst($c->kind)) }}
                                    @if ($c->checkup_on)
                                        <span class="ml-1 text-neutral-500">· {{ \App\Support\Formatting::date($c->checkup_on) }}</span>
                                    @endif
                                </span>
                                <span class="shrink-0 tabular-nums {{ $overdue ? 'text-rose-300' : 'text-neutral-500' }}">
                                    @if ($c->next_due_on)
                                        {{ $overdue ? __('overdue') : __('next') }} {{ \App\Support\Formatting::date($c->next_due_on) }}
                                    @else
                                        —
                                    @endif
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-2 text-[11px] text-neutral-500">{{ __('No checkups recorded yet.') }}</p>
            @endif
        </fieldset>
    @endif
</form>

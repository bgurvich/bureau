<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div class="grid grid-cols-3 gap-3">
        <div>
            <label for="i-fd-kind" class="mb-1 block text-xs text-neutral-400">{{ __('Kind') }}</label>
            <select wire:model="kind" id="i-fd-kind" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::foodEntryKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-span-2">
            <label for="i-fd-label" class="mb-1 block text-xs text-neutral-400">{{ __('What') }}</label>
            <input wire:model="label" id="i-fd-label" type="text" required autofocus
                   placeholder="{{ __('Oatmeal with berries, coffee, …') }}"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('label')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div>
        <label for="i-fd-at" class="mb-1 block text-xs text-neutral-400">{{ __('When') }}</label>
        <input wire:model="eaten_at" id="i-fd-at" type="datetime-local" required
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('eaten_at')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <fieldset class="space-y-3 rounded-md border border-neutral-800 bg-neutral-900/40 px-3 py-3">
        <legend class="px-1 text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Nutrition (optional)') }}</legend>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="i-fd-svg" class="mb-1 block text-xs text-neutral-400">{{ __('Servings') }}</label>
                <input wire:model="servings" id="i-fd-svg" type="number" step="0.25" min="0"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            </div>
            <div>
                <label for="i-fd-cal" class="mb-1 block text-xs text-neutral-400">{{ __('Calories') }}</label>
                <input wire:model="calories" id="i-fd-cal" type="number" step="1" min="0"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            </div>
        </div>
        <div class="grid grid-cols-3 gap-3">
            <div>
                <label for="i-fd-p" class="mb-1 block text-xs text-neutral-400">{{ __('Protein (g)') }}</label>
                <input wire:model="protein_g" id="i-fd-p" type="number" step="0.1" min="0"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            </div>
            <div>
                <label for="i-fd-c" class="mb-1 block text-xs text-neutral-400">{{ __('Carbs (g)') }}</label>
                <input wire:model="carbs_g" id="i-fd-c" type="number" step="0.1" min="0"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            </div>
            <div>
                <label for="i-fd-f" class="mb-1 block text-xs text-neutral-400">{{ __('Fat (g)') }}</label>
                <input wire:model="fat_g" id="i-fd-f" type="number" step="0.1" min="0"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            </div>
        </div>
    </fieldset>

    @include('partials.inspector.fields.photos')
    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

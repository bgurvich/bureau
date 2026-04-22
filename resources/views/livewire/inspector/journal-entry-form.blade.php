<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div class="grid grid-cols-3 gap-3">
        <div class="col-span-1">
            <label for="i-je-date" class="mb-1 block text-xs text-neutral-400">{{ __('Date') }}</label>
            <input wire:model="occurred_on" id="i-je-date" type="date" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('occurred_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div class="col-span-2">
            <label for="i-je-title" class="mb-1 block text-xs text-neutral-400">{{ __('Title') }}</label>
            <input wire:model="title" id="i-je-title" type="text" autofocus
                   placeholder="{{ __('Optional — a few words that jog your memory') }}"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>

    <div>
        <label for="i-je-body" class="mb-1 block text-xs text-neutral-400">{{ __('Entry') }}</label>
        <textarea wire:model="body" id="i-je-body" rows="10" required
                  placeholder="{{ __('How was today? What happened, what did you notice, what do you want to remember?') }}"
                  class="w-full resize-y rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm leading-relaxed text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
        @error('body')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <div class="grid grid-cols-3 gap-3">
        <div>
            <label for="i-je-mood" class="mb-1 block text-xs text-neutral-400">{{ __('Mood') }}</label>
            <select wire:model="mood" id="i-je-mood"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">—</option>
                @foreach (App\Support\Enums::journalMoods() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="i-je-weather" class="mb-1 block text-xs text-neutral-400">{{ __('Weather') }}</label>
            <input wire:model="weather" id="i-je-weather" type="text"
                   placeholder="{{ __('sunny, 18°C') }}"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-je-location" class="mb-1 block text-xs text-neutral-400">{{ __('Location') }}</label>
            <input wire:model="location" id="i-je-location" type="text"
                   placeholder="{{ __('Home, SFO airport…') }}"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>

    <label class="flex items-center gap-2 text-sm text-neutral-200">
        <input wire:model="private" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
        <span>{{ __('Private (only visible to me)') }}</span>
    </label>

    @include('partials.inspector.fields.subjects')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

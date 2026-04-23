<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div>
        <label for="i-gl-title" class="mb-1 block text-xs text-neutral-400">{{ __('Title') }}</label>
        <input wire:model="title" id="i-gl-title" type="text" required autofocus
               placeholder="{{ __('Read 20 books, Run 500 miles by Dec, …') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('title')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    {{-- Mode segmented — target (finite: value + deadline) vs direction (infinite: cadence nudge). --}}
    <div>
        <span class="mb-1 block text-xs text-neutral-400">{{ __('Mode') }}</span>
        <div role="radiogroup" aria-label="{{ __('Goal mode') }}"
             class="inline-flex rounded-md border border-neutral-700 bg-neutral-950 p-0.5 text-xs">
            @foreach ([
                'target' => __('Target'),
                'direction' => __('Direction'),
            ] as $v => $l)
                <button type="button" role="radio"
                        wire:click="$set('mode', '{{ $v }}')"
                        aria-checked="{{ $mode === $v ? 'true' : 'false' }}"
                        class="rounded px-3 py-1.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $mode === $v ? 'bg-neutral-800 text-neutral-100' : 'text-neutral-400 hover:text-neutral-200' }}">
                    {{ $l }}
                </button>
            @endforeach
        </div>
        <p class="mt-1 text-[11px] text-neutral-500">
            @if ($mode === 'target')
                {{ __('A value to hit — progress + pace against a deadline.') }}
            @else
                {{ __('A direction to stay on — no target, optional check-in cadence.') }}
            @endif
        </p>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-gl-category" class="mb-1 block text-xs text-neutral-400">{{ __('Category') }}</label>
            <select wire:model="category" id="i-gl-category"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (\App\Support\Enums::goalCategories() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="i-gl-status" class="mb-1 block text-xs text-neutral-400">{{ __('Status') }}</label>
            <select wire:model="status" id="i-gl-status"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (\App\Support\Enums::goalStatuses() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($mode === 'target')
        <div class="grid grid-cols-3 gap-3">
            <div>
                <label for="i-gl-target" class="mb-1 block text-xs text-neutral-400">{{ __('Target') }}</label>
                <input wire:model="target_value" id="i-gl-target" type="number" step="0.01" min="0" required inputmode="decimal"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @error('target_value')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="i-gl-current" class="mb-1 block text-xs text-neutral-400">{{ __('Progress') }}</label>
                <input wire:model="current_value" id="i-gl-current" type="number" step="0.01" min="0" required inputmode="decimal"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @error('current_value')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="i-gl-unit" class="mb-1 block text-xs text-neutral-400">{{ __('Unit') }}</label>
                <input wire:model="unit" id="i-gl-unit" type="text" maxlength="32"
                       placeholder="{{ __('books, mi, kg, …') }}"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="i-gl-started" class="mb-1 block text-xs text-neutral-400">{{ __('Started on') }}</label>
                <input wire:model="started_on" id="i-gl-started" type="date"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            </div>
            <div>
                <label for="i-gl-target-date" class="mb-1 block text-xs text-neutral-400">{{ __('Target date') }}</label>
                <input wire:model="target_date" id="i-gl-target-date" type="date"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @error('target_date')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
            </div>
        </div>
    @else
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="i-gl-started" class="mb-1 block text-xs text-neutral-400">{{ __('Started on') }}</label>
                <input wire:model="started_on" id="i-gl-started" type="date"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            </div>
            <div>
                <label for="i-gl-cadence" class="mb-1 block text-xs text-neutral-400">{{ __('Check-in every (days)') }}</label>
                <input wire:model="cadence_days" id="i-gl-cadence" type="number" min="1" max="365" step="1" inputmode="numeric"
                       placeholder="{{ __('7') }}"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <p class="mt-1 text-[11px] text-neutral-500">{{ __('Blank = no nudge.') }}</p>
                @error('cadence_days')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
            </div>
        </div>
    @endif

    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

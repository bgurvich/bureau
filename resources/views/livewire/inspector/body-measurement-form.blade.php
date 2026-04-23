<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div>
        <label for="i-bm-at" class="mb-1 block text-xs text-neutral-400">{{ __('Measured at') }}</label>
        <input wire:model="measured_at" id="i-bm-at" type="datetime-local" required autofocus
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('measured_at')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <div class="grid grid-cols-3 gap-3">
        <div>
            <label for="i-bm-w" class="mb-1 block text-xs text-neutral-400">{{ __('Weight') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $weight_unit }}</span>
            </label>
            <input wire:model="weight" id="i-bm-w" type="number" step="0.1" min="0" inputmode="decimal"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('weight')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-bm-f" class="mb-1 block text-xs text-neutral-400">{{ __('Body fat') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">%</span>
            </label>
            <input wire:model="body_fat_pct" id="i-bm-f" type="number" step="0.1" min="0" max="80" inputmode="decimal"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-bm-m" class="mb-1 block text-xs text-neutral-400">{{ __('Muscle') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">%</span>
            </label>
            <input wire:model="muscle_pct" id="i-bm-m" type="number" step="0.1" min="0" max="80" inputmode="decimal"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>

    <div>
        <span class="mb-1 block text-xs text-neutral-400">{{ __('Weight unit') }}</span>
        <div role="radiogroup" aria-label="{{ __('Weight unit') }}"
             class="inline-flex rounded-md border border-neutral-700 bg-neutral-950 p-0.5 text-xs">
            @foreach (['lb' => 'lb', 'kg' => 'kg'] as $v => $l)
                <button type="button" role="radio"
                        wire:click="$set('weight_unit', '{{ $v }}')"
                        aria-checked="{{ $weight_unit === $v ? 'true' : 'false' }}"
                        class="rounded px-3 py-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $weight_unit === $v ? 'bg-neutral-800 text-neutral-100' : 'text-neutral-400 hover:text-neutral-200' }}">
                    {{ $l }}
                </button>
            @endforeach
        </div>
        <p class="mt-1 text-[11px] text-neutral-500">{{ __('Stored internally as kg; unit affects entry + display only.') }}</p>
    </div>

    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.admin')
</form>

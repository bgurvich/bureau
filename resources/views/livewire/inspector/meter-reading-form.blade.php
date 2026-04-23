<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-mr-prop" class="mb-1 block text-xs text-neutral-400">{{ __('Property') }}</label>
            <select wire:model="property_id" id="i-mr-prop" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">—</option>
                @foreach ($this->properties as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                @endforeach
            </select>
            @error('property_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-mr-kind" class="mb-1 block text-xs text-neutral-400">{{ __('Kind') }}</label>
            <select wire:model.live="kind" id="i-mr-kind" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::meterReadingKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-3">
        <div>
            <label for="i-mr-date" class="mb-1 block text-xs text-neutral-400">{{ __('Read on') }}</label>
            <input wire:model="read_on" id="i-mr-date" type="date" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('read_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-mr-val" class="mb-1 block text-xs text-neutral-400">{{ __('Value') }}</label>
            <input wire:model="value" id="i-mr-val" type="number" step="0.0001" required autofocus
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('value')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-mr-unit" class="mb-1 block text-xs text-neutral-400">{{ __('Unit') }}</label>
            <input wire:model="unit" id="i-mr-unit" type="text" required
                   placeholder="kWh, gal, therm, m³, GB…"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('unit')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
</form>

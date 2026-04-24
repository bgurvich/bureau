<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div>
        <label for="i-loc-name" class="mb-1 block text-xs text-neutral-400">{{ __('Name') }}</label>
        <input wire:model="location_name" id="i-loc-name" type="text" required autofocus
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('location_name')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-loc-kind" class="mb-1 block text-xs text-neutral-400">{{ __('Kind') }}</label>
            <select wire:model="location_kind" id="i-loc-kind"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="area">{{ __('Area') }}</option>
                <option value="room">{{ __('Room') }}</option>
                <option value="container">{{ __('Container') }}</option>
                <option value="other">{{ __('Other') }}</option>
            </select>
        </div>
        <div>
            <label for="i-loc-property" class="mb-1 block text-xs text-neutral-400">{{ __('Property') }}</label>
            <select wire:model="location_property_id" id="i-loc-property"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">— {{ __('none') }} —</option>
                @foreach ($this->propertyPickerOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div>
        <label for="i-loc-parent" class="mb-1 block text-xs text-neutral-400">{{ __('Parent') }}</label>
        <x-ui.searchable-select
            id="i-loc-parent"
            model="location_parent_id"
            :options="['' => '— '.__('none').' —'] + $this->parentPickerOptions"
            placeholder="{{ __('— root —') }}"
            edit-inspector-type="location" />
        @error('location_parent_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>
</form>

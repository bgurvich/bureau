<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div>
        <label for="i-m-title" class="mb-1 block text-xs text-neutral-400">{{ __('Title') }}</label>
        <input wire:model="meeting_title" id="i-m-title" type="text" required autofocus
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('meeting_title')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-m-starts" class="mb-1 block text-xs text-neutral-400">{{ __('Starts') }}</label>
            <input wire:model="starts_at" id="i-m-starts" type="datetime-local" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('starts_at')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-m-ends" class="mb-1 block text-xs text-neutral-400">{{ __('Ends') }}</label>
            <input wire:model="ends_at" id="i-m-ends" type="datetime-local" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('ends_at')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>
    <div>
        <label for="i-m-location" class="mb-1 block text-xs text-neutral-400">{{ __('Location') }}</label>
        <input wire:model="location" id="i-m-location" type="text" placeholder="{{ __('Zoom, address, phone…') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>
    <div>
        <label for="i-m-url" class="mb-1 block text-xs text-neutral-400">{{ __('Meeting URL') }}</label>
        <input wire:model="meeting_url" id="i-m-url" type="url" inputmode="url"
               placeholder="https://…"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>
    <label class="flex items-center gap-2 text-sm text-neutral-200">
        <input wire:model="all_day" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
        {{ __('All day') }}
    </label>
    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

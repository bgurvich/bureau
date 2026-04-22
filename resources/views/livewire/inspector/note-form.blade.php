<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div>
        <label for="i-n-title" class="mb-1 block text-xs text-neutral-400">{{ __('Title') }}</label>
        <input wire:model="title" id="i-n-title" type="text" autofocus
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>
    <div>
        <label for="i-n-body" class="mb-1 block text-xs text-neutral-400">{{ __('Body') }}</label>
        <textarea wire:model="body" id="i-n-body" rows="8" required
                  class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
        @error('body')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>
    <div class="flex gap-4 text-xs">
        <label class="flex items-center gap-2 text-neutral-300">
            <input wire:model="pinned" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Pinned') }}
        </label>
        <label class="flex items-center gap-2 text-neutral-300">
            <input wire:model="private" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Private') }}
        </label>
    </div>
    @include('partials.inspector.fields.subjects')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>

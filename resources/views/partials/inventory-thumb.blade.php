@php
    /** @var \App\Models\InventoryItem $i */
    /** @var string $size */
    $photo = $i->relationLoaded('media') ? $i->media->first() : $i->media()->wherePivot('role', 'photo')->first();
    $size ??= 'sm';
    $dim = $size === 'lg' ? 'h-16 w-16' : 'h-12 w-12';
@endphp
@if ($photo)
    <div x-data="{ open: false }" class="shrink-0">
        <button type="button"
                x-on:click.stop="open = true"
                class="{{ $dim }} overflow-hidden rounded-md border border-neutral-800 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                aria-label="{{ __('View photo of :name', ['name' => $i->name]) }}">
            <img src="{{ route('media.file', $photo) }}" alt="" class="h-full w-full object-cover" loading="lazy" />
        </button>
        <template x-teleport="body">
            <div x-show="open" x-cloak x-transition.opacity
                 x-on:click="open = false"
                 x-on:keydown.escape.window="open = false"
                 class="fixed inset-0 z-[60] flex items-center justify-center bg-black/90 p-6"
                 role="dialog" aria-modal="true" aria-label="{{ __('Photo preview') }}">
                <img src="{{ route('media.file', $photo) }}" alt="{{ $i->name }}"
                     class="max-h-full max-w-full rounded-lg shadow-2xl" />
                <button type="button" x-on:click.stop="open = false"
                        class="fixed right-4 top-4 rounded-full bg-neutral-900/80 p-2 text-neutral-100 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                        aria-label="{{ __('Close') }}">
                    <svg class="h-5 w-5" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
        </template>
    </div>
@else
    <div class="{{ $dim }} shrink-0 rounded-md border border-dashed border-neutral-800 bg-neutral-900/40" aria-hidden="true"></div>
@endif

@php
    $photos = $this->inspectorPhotos();
    $modelClass = $this->adminModelMap()[0] ?? '';
    $typeSupportsMedia = $modelClass && method_exists($modelClass, 'media');
    // Allow attaching even before the record exists for types where create
    // via photo is meaningful — inventory / physical_mail / food_entry
    // draft themselves through ensureDraftForPhoto() before the modal opens.
    $allowCreateWithPhoto = in_array($this->type, ['inventory', 'physical_mail', 'food_entry'], true);
    $canAttach = $typeSupportsMedia && ($this->id || $allowCreateWithPhoto);
@endphp
@if ($canAttach)
    <hr class="border-neutral-800">
    <h4 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">
        {{ __('Photos') }}
        @if ($photos->isNotEmpty())
            <span class="ml-1 text-neutral-600">· {{ __('first is cover, drag to reorder') }}</span>
        @endif
    </h4>

    <div x-data="{ open: null }">
        {{-- Thumbnails + the Add tile live in ONE flex-wrap row so the Add
             tile sits next to the last thumbnail when there's space and
             wraps when the list overflows. The bare <li> at the end has
             no data-item-key so Alpine's sortable ignores it. Clicking
             "Add" opens the library modal — pick from existing or drop
             new files to upload + attach in one step. --}}
        <x-ui.sortable-list reorder-method="reorderPhotos" class="flex flex-wrap gap-2">
            @foreach ($photos as $photo)
                <x-ui.sortable-row :item-key="$photo->id" :no-handle="true"
                                   class="group relative">
                    <button type="button"
                            x-on:click.stop="open = {{ $photo->id }}"
                            class="h-16 w-16 cursor-grab overflow-hidden rounded-md border border-neutral-800 bg-neutral-950 active:cursor-grabbing focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                            aria-label="{{ __('View photo :name', ['name' => $photo->original_name ?: __('Photo')]) }}">
                        <img src="{{ route('media.file', $photo) }}" alt="" class="h-full w-full object-cover" loading="lazy" />
                    </button>
                    @if ($loop->first)
                        <span aria-hidden="true"
                              class="pointer-events-none absolute left-1 top-1 rounded bg-emerald-600/90 px-1 text-[10px] font-medium uppercase tracking-wider text-white">
                            {{ __('cover') }}
                        </span>
                    @endif
                    <button type="button"
                            wire:click="deletePhoto({{ $photo->id }})"
                            wire:confirm="{{ __('Delete this photo?') }}"
                            aria-label="{{ __('Delete photo') }}"
                            class="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-neutral-900 text-neutral-300 opacity-0 transition group-hover:opacity-100 focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <svg class="h-3 w-3" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                            <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </x-ui.sortable-row>
            @endforeach

            <li>
                <button type="button"
                        wire:click="openMediaLibrary"
                        aria-label="{{ __('Add photo from library or upload') }}"
                        class="flex h-16 w-16 flex-col items-center justify-center gap-0.5 rounded-md border-2 border-dashed border-neutral-700 text-neutral-500 transition-colors hover:border-neutral-500 hover:text-neutral-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path d="M8 3v10M3 8h10" stroke-linecap="round"/>
                    </svg>
                    <span class="text-[10px]">{{ __('Add') }}</span>
                </button>
            </li>
        </x-ui.sortable-list>

        <template x-teleport="body">
            <div x-show="open !== null" x-cloak x-transition.opacity
                 x-on:click="open = null"
                 x-on:keydown.escape.window="open = null"
                 class="fixed inset-0 z-[70] flex items-center justify-center bg-black/90 p-6"
                 role="dialog" aria-modal="true" aria-label="{{ __('Photo preview') }}">
                @foreach ($photos as $photo)
                    <img x-show="open === {{ $photo->id }}"
                         src="{{ route('media.file', $photo) }}"
                         alt="{{ $photo->original_name }}"
                         class="max-h-full max-w-full rounded-lg shadow-2xl" />
                @endforeach
                <button type="button" x-on:click.stop="open = null"
                        class="fixed right-4 top-4 rounded-full bg-neutral-900/80 p-2 text-neutral-100 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                        aria-label="{{ __('Close') }}">
                    <svg class="h-5 w-5" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
        </template>
    </div>
@endif

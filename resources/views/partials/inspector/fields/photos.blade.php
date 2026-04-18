@php
    $photos = $this->inspectorPhotos();
    $modelClass = $this->adminModelMap()[0] ?? '';
    $typeSupportsMedia = $modelClass && method_exists($modelClass, 'media');
    // Allow attaching even before the record exists for types where create
    // via photo is meaningful — inventory is the only one today, matching
    // the mobile photo-first capture flow.
    $allowCreateWithPhoto = in_array($this->type, ['inventory'], true);
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

    <div x-data="{
            open: null,
            dragFrom: null,
            order: @js($photos->pluck('id')->all()),
            dragStart(id) { this.dragFrom = id; },
            dragOver(e) { e.preventDefault(); },
            drop(targetId) {
                if (this.dragFrom === null || this.dragFrom === targetId) { this.dragFrom = null; return; }
                const from = this.order.indexOf(this.dragFrom);
                const to = this.order.indexOf(targetId);
                if (from === -1 || to === -1) { this.dragFrom = null; return; }
                this.order.splice(to, 0, this.order.splice(from, 1)[0]);
                this.$wire.reorderPhotos(this.order);
                this.dragFrom = null;
            }
         }"
         class="flex flex-wrap gap-2">
        @foreach ($photos as $photo)
            <div wire:key="insp-photo-{{ $photo->id }}"
                 draggable="true"
                 x-on:dragstart="dragStart({{ $photo->id }})"
                 x-on:dragover="dragOver($event)"
                 x-on:drop="drop({{ $photo->id }})"
                 x-bind:class="dragFrom === {{ $photo->id }} ? 'opacity-40' : ''"
                 class="group relative">
                <button type="button"
                        x-on:click.stop="open = {{ $photo->id }}"
                        class="h-16 w-16 cursor-grab overflow-hidden rounded-md border border-neutral-800 bg-neutral-950 active:cursor-grabbing focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                        aria-label="{{ __('View photo :name', ['name' => $photo->original_name ?: __('Photo')]) }}">
                    <img src="{{ route('media.file', $photo) }}" alt="" class="h-full w-full object-cover" loading="lazy" />
                </button>
                @if ($loop->first)
                    <span aria-hidden="true"
                          class="pointer-events-none absolute left-1 top-1 rounded bg-emerald-600/90 px-1 text-[9px] font-medium uppercase tracking-wider text-white">
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
            </div>
        @endforeach

        <label class="flex h-16 w-16 cursor-pointer flex-col items-center justify-center gap-0.5 rounded-md border border-dashed border-neutral-700 text-neutral-500 hover:border-neutral-500 hover:text-neutral-300 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-neutral-300"
               for="insp-photo-upload">
            <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path d="M8 3v10M3 8h10" stroke-linecap="round"/>
            </svg>
            <span class="text-[10px]">{{ __('Add') }}</span>
        </label>
        <input id="insp-photo-upload" type="file" wire:model="photoUpload" accept="image/*" multiple class="sr-only" />

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
    @error('photoUpload')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    <div wire:loading wire:target="photoUpload,addPhoto" class="text-xs text-neutral-500">{{ __('Uploading…') }}</div>
@endif

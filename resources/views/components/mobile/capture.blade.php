<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.mobile', ['title' => 'Secretaire'])]
class extends Component
{
    //
};
?>

<div class="space-y-5">
    <header class="pt-2">
        <h1 class="text-lg font-semibold text-neutral-100">{{ __('Capture') }}</h1>
        <p class="mt-1 text-xs text-neutral-500">{{ __('Quick in, describe later.') }}</p>
    </header>

    @php
        /* Shared tile styling — anchor + button rows share this spine so
         * tapping either flows to the same visual target size. */
        $tileClass = 'flex w-full items-center gap-4 rounded-2xl border border-neutral-800 bg-neutral-900/60 px-4 py-4 text-left active:bg-neutral-900/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300';
        $iconBox = 'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-neutral-800 text-neutral-200';
    @endphp

    <h2 class="mt-1 px-1 text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Photo / scan') }}</h2>
    <ul class="space-y-3">
        <li>
            <a href="{{ route('mobile.capture.inventory') }}"
               class="{{ $tileClass }}">
                <span class="{{ $iconBox }}">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 7h4l1-2h8l1 2h4v12H3z"/><circle cx="12" cy="13" r="4"/>
                    </svg>
                </span>
                <span class="flex-1">
                    <span class="block text-sm font-medium text-neutral-100">{{ __('Photo inventory') }}</span>
                    <span class="block text-xs text-neutral-500">{{ __('Snap items to describe later.') }}</span>
                </span>
                <span aria-hidden="true" class="text-neutral-500">›</span>
            </a>
        </li>
        <li>
            <a href="{{ route('mobile.capture.note') }}"
               class="flex w-full items-center gap-4 rounded-2xl border border-neutral-800 bg-neutral-900/60 px-4 py-4 text-left active:bg-neutral-900/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-neutral-800 text-neutral-200">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="9" y="3" width="6" height="12" rx="3"/>
                        <path d="M5 12a7 7 0 0 0 14 0"/>
                        <path d="M12 19v3"/>
                    </svg>
                </span>
                <span class="flex-1">
                    <span class="block text-sm font-medium text-neutral-100">{{ __('Voice or text note') }}</span>
                    <span class="block text-xs text-neutral-500">{{ __('Dictate or type, saved as a Note.') }}</span>
                </span>
                <span aria-hidden="true" class="text-neutral-500">›</span>
            </a>
        </li>
        <li>
            <a href="{{ route('mobile.capture.photo') }}"
               class="{{ $tileClass }}">
                <span class="{{ $iconBox }}">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="4" y="4" width="16" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m4 18 6-6 4 4 3-3 3 3"/>
                    </svg>
                </span>
                <span class="flex-1">
                    <span class="block text-sm font-medium text-neutral-100">{{ __('Receipt / Bill / Document / Post') }}</span>
                    <span class="block text-xs text-neutral-500">{{ __('Pick a kind on the next screen; each routes to its own folder.') }}</span>
                </span>
                <span aria-hidden="true" class="text-neutral-500">›</span>
            </a>
        </li>
        <li>
            <a href="{{ route('mobile.tasks.bulk') }}"
               class="{{ $tileClass }}">
                <span class="{{ $iconBox }}">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9 11l3 3L22 4"/>
                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                    </svg>
                </span>
                <span class="flex-1">
                    <span class="block text-sm font-medium text-neutral-100">{{ __('Bulk tasks') }}</span>
                    <span class="block text-xs text-neutral-500">{{ __('Dump a list, one line per task.') }}</span>
                </span>
                <span aria-hidden="true" class="text-neutral-500">›</span>
            </a>
        </li>
    </ul>

    {{-- Life-event capture parity with desktop quick-add. Each button
         opens the mobile inspector drawer for that type; the food tile's
         built-in photo-first flow lets mobile users shoot a plate and
         fill macros later without a dedicated screen. --}}
    <h2 class="mt-5 px-1 text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Life event') }}</h2>
    <ul class="space-y-3">
        @php
            $lifeTiles = [
                ['food_entry', __('Food'), __('Meal, snack, drink — photo optional.'), 'plate'],
                ['journal_entry', __('Journal entry'), __('Today\'s reflection.'), 'book'],
                ['decision', __('Decision'), __('Something you decided + why.'), 'check-square'],
                ['media_log_entry', __('Reading / watching'), __('Book, show, film in the log.'), 'note'],
                ['goal', __('Goal'), __('New target or direction to track.'), 'pie'],
            ];
        @endphp
        @foreach ($lifeTiles as [$type, $title, $subtitle, $icon])
            <li>
                <button type="button"
                        x-data
                        x-on:click="Livewire.dispatch('inspector-open', { type: @js($type) })"
                        class="{{ $tileClass }}">
                    <span class="{{ $iconBox }}">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            @switch($icon)
                                @case('plate')
                                    <circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4"/>
                                    @break
                                @case('book')
                                    <path d="M4 5a2 2 0 0 1 2-2h12v18H6a2 2 0 0 1-2-2V5Z"/>
                                    <path d="M4 17h14"/>
                                    @break
                                @case('check-square')
                                    <rect x="4" y="4" width="16" height="16" rx="2"/>
                                    <path d="m9 12 2 2 4-4"/>
                                    @break
                                @case('note')
                                    <path d="M6 3h9l5 5v13H6z"/>
                                    <path d="M15 3v5h5"/>
                                    @break
                                @case('pie')
                                    <path d="M12 3v9l8 4"/>
                                    <circle cx="12" cy="12" r="9"/>
                                    @break
                            @endswitch
                        </svg>
                    </span>
                    <span class="flex-1">
                        <span class="block text-sm font-medium text-neutral-100">{{ $title }}</span>
                        <span class="block text-xs text-neutral-500">{{ $subtitle }}</span>
                    </span>
                    <span aria-hidden="true" class="text-neutral-500">＋</span>
                </button>
            </li>
        @endforeach
    </ul>
</div>

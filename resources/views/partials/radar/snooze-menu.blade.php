{{-- Alpine popover that lets the user silence this radar tile for
     3/7/30 days. Clicks bubble to stop-propagation on the list row so
     the tile's own link isn't triggered when the menu is in play. --}}
<div x-data="{ open: false }"
     x-on:click.stop
     x-on:click.outside="open = false"
     x-on:keydown.escape.window="open = false"
     class="relative shrink-0">
    <button type="button"
            x-on:click="open = ! open"
            aria-haspopup="menu"
            :aria-expanded="open.toString()"
            aria-label="{{ __('Snooze signal') }}"
            title="{{ __('Snooze this signal') }}"
            class="flex h-4 w-4 items-center justify-center rounded text-neutral-600 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <svg class="h-3 w-3" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 3h5L3 9h5"/>
        </svg>
    </button>
    <div x-show="open"
         x-cloak
         x-transition.opacity.duration.75ms
         role="menu"
         class="absolute right-0 top-5 z-20 w-36 overflow-hidden rounded-md border border-neutral-700 bg-neutral-900 text-xs shadow-xl">
        @foreach ([3, 7, 30] as $d)
            <button type="button"
                    role="menuitem"
                    wire:click="snoozeSignal('{{ $kind }}', {{ $d }})"
                    x-on:click="open = false"
                    class="block w-full px-3 py-1.5 text-left text-neutral-200 hover:bg-neutral-800 focus-visible:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ __('Snooze :n days', ['n' => $d]) }}
            </button>
        @endforeach
    </div>
</div>

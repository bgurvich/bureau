@php
    $tabs = [
        ['mobile.home', __('Home'), 'home'],
        ['mobile.capture', __('Capture'), 'camera'],
        ['mobile.inbox', __('Inbox'), 'inbox'],
        ['mobile.search', __('Search'), 'search'],
        ['mobile.me', __('Me'), 'user'],
    ];
@endphp
<nav aria-label="{{ __('Mobile navigation') }}"
     class="mobile-tabs fixed inset-x-0 bottom-0 z-40 border-t border-neutral-800 bg-neutral-950/95 backdrop-blur">
    <ul class="mx-auto flex max-w-md items-stretch">
        @foreach ($tabs as [$route, $label, $icon])
            @php $active = request()->routeIs($route); @endphp
            <li class="flex-1">
                <a href="{{ route($route) }}"
                   @if ($active) aria-current="page" @endif
                   class="flex h-16 flex-col items-center justify-center gap-1 text-[11px] transition focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-neutral-300
                          {{ $active ? 'text-neutral-50' : 'text-neutral-500 hover:text-neutral-200' }}">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true"
                         stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none">
                        @switch($icon)
                            @case('home')
                                <path d="M3 11 12 4l9 7"/>
                                <path d="M5 10v10h5v-6h4v6h5V10"/>
                                @break
                            @case('camera')
                                <path d="M3 8a2 2 0 0 1 2-2h2.5l1.5-2h6l1.5 2H19a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8Z"/>
                                <circle cx="12" cy="13" r="4"/>
                                @break
                            @case('inbox')
                                <path d="M3 13h5l1.5 2.5h5L16 13h5"/>
                                <path d="M5 5h14l2 8v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6L5 5Z"/>
                                @break
                            @case('search')
                                <circle cx="11" cy="11" r="7"/>
                                <path d="m20 20-4-4"/>
                                @break
                            @case('user')
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M4 21a8 8 0 0 1 16 0"/>
                                @break
                        @endswitch
                    </svg>
                    <span>{{ $label }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>

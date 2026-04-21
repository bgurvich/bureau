<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ auth()->user()?->theme ?? 'system' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    {{-- Resolve the theme SYNCHRONOUSLY before CSS paints. Without this,
         the browser renders with default (dark) styling for a frame before
         resources/js/app.ts runs and flips data-resolved-theme. --}}
    @include('partials.theme-flash')
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="min-h-screen bg-neutral-950 text-neutral-100 antialiased">
    <a href="#main"
       class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-neutral-100 focus:px-3 focus:py-2 focus:text-sm focus:text-neutral-900 focus:outline-none">
        {{ __('Skip to main content') }}
    </a>

    <div class="flex min-h-screen"
         x-data="{ navOpen: false }"
         x-on:keydown.escape.window="navOpen = false"
         x-on:resize.window.debounce.100ms="if (window.innerWidth >= 768) navOpen = false">
        <div x-show="navOpen"
             x-transition.opacity
             x-on:click="navOpen = false"
             class="fixed inset-0 z-30 bg-neutral-950/70 md:hidden"
             aria-hidden="true"
             style="display: none"></div>
        <aside class="fixed inset-y-0 left-0 z-40 w-60 shrink-0 -translate-x-full transform border-r border-neutral-800 bg-neutral-950 transition-transform duration-200 md:static md:translate-x-0 md:bg-neutral-900/50"
               x-bind:class="navOpen ? 'translate-x-0' : ''"
               x-bind:aria-hidden="navOpen ? 'false' : undefined"
               aria-label="{{ __('Primary') }}">
            <div class="flex h-full flex-col md:sticky md:top-0 md:h-screen">
                <div class="border-b border-neutral-800 px-5 py-4">
                    <div class="text-base font-semibold tracking-tight">{{ __('Bureau') }}</div>
                    @auth
                        @php $h = \App\Support\CurrentHousehold::get(); @endphp
                        @if ($h)
                            <div class="mt-0.5 text-xs text-neutral-500">{{ $h->name }}</div>
                        @endif
                    @endauth
                </div>
                <nav id="main-nav" class="flex-1 overflow-y-auto px-3 py-4 text-sm" aria-label="{{ __('Main navigation') }}">
                    @php
                        $sections = [
                            null => [
                                [__('Dashboard'), 'dashboard', 'home'],
                                [__('Weekly review'), 'review', 'check-square'],
                            ],
                            __('Money') => [
                                [__('Overview'), 'fiscal.overview', 'pie'],
                                [__('Ledger'), 'fiscal.ledger', 'wallet'],
                                [__('Bills & Income'), 'fiscal.recurring', 'receipt'],
                                [__('Subscriptions'), 'fiscal.subscriptions', 'key'],
                                [__('Year over year'), 'fiscal.yoy', 'pie'],
                                [__('Budgets'), 'fiscal.budgets', 'pie'],
                                [__('Rules'), 'fiscal.rules', 'note'],
                                [__('Savings goals'), 'fiscal.savings_goals', 'check-square'],
                                [__('Inbox'), 'fiscal.inbox', 'note'],
                            ],
                            __('Life') => [
                                [__('Schedule'), 'life.schedule', 'calendar'],
                                [__('Contacts'), 'relationships.contacts', 'user'],
                            ],
                            __('Time') => [
                                [__('Projects'), 'time.projects', 'folder'],
                                [__('Time entries'), 'time.entries', 'clock'],
                            ],
                            __('Commitments') => [
                                [__('Contracts'), 'relationships.contracts', 'file-signature'],
                                [__('Insurance'), 'relationships.insurance', 'shield'],
                            ],
                            __('Assets') => [
                                [__('Assets'), 'assets.index', 'box'],
                            ],
                            __('Records') => [
                                [__('Records'), 'records.index', 'file-text'],
                            ],
                            __('Health') => [
                                [__('Providers'), 'health.providers', 'stethoscope'],
                                [__('Prescriptions'), 'health.prescriptions', 'pill'],
                                [__('Appointments'), 'health.appointments', 'calendar-clock'],
                            ],
                        ];
                    @endphp
                    @foreach ($sections as $heading => $items)
                        @if ($heading)
                            <div class="mt-4 mb-1 px-3 text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ $heading }}</div>
                        @endif
                        @foreach ($items as [$label, $route, $icon])
                            @php $active = request()->routeIs($route); @endphp
                            <a href="{{ route($route) }}"
                               @if($active) aria-current="page" @endif
                               class="flex items-center gap-2.5 rounded-md px-3 py-1.5 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300
                                      {{ $active ? 'bg-neutral-800 text-neutral-50' : 'text-neutral-400 hover:bg-neutral-800/60 hover:text-neutral-100' }}">
                                @include('partials.nav-icon', ['name' => $icon])
                                <span>{{ $label }}</span>
                            </a>
                        @endforeach
                    @endforeach
                    @include('partials.nav-scroll-init')
                </nav>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-14 items-center justify-between gap-2 border-b border-neutral-800 px-4 md:px-6">
                <div class="flex min-w-0 items-center gap-2">
                    <button type="button"
                            x-on:click="navOpen = !navOpen"
                            x-bind:aria-expanded="navOpen"
                            aria-controls="main-nav"
                            aria-label="{{ __('Toggle navigation') }}"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-neutral-800 bg-neutral-900 text-neutral-300 hover:border-neutral-700 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 md:hidden">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"
                             stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                            <path d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    <h1 class="truncate text-sm font-medium text-neutral-300">{{ $title ?? __('Dashboard') }}</h1>
                </div>
                <div class="flex shrink-0 items-center gap-2 md:gap-3">
                    @auth
                        <livewire:time-tracker />
                    @endauth
                    @auth
                        <button type="button"
                                x-data
                                x-on:click="Livewire.dispatch('global-search-open')"
                                title="{{ __('Search') }} (/)"
                                aria-label="{{ __('Search') }}"
                                class="flex h-8 w-8 items-center justify-center rounded-md border border-neutral-800 bg-neutral-900 text-neutral-300 hover:border-neutral-700 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"
                                 stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="7"/>
                                <path d="m20 20-4-4"/>
                            </svg>
                        </button>
                        <button type="button"
                                x-data
                                x-on:click="Livewire.dispatch('inspector-open')"
                                title="{{ __('Quick add') }} (.)"
                                aria-label="{{ __('Quick add') }}"
                                class="flex h-8 w-8 items-center justify-center rounded-md border border-neutral-800 bg-neutral-900 text-neutral-300 hover:border-neutral-700 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" aria-hidden="true"
                                 stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                                <path d="M8 3v10M3 8h10"/>
                            </svg>
                        </button>
                        <livewire:alerts-bell />
                        <livewire:user-menu />
                    @endauth
                </div>
            </header>
            <main id="main" tabindex="-1" class="flex-1 p-4 md:p-6">
                {{ $slot }}
            </main>
        </div>
    </div>

    @auth
        <livewire:inspector />
        <livewire:global-search />
    @endauth
</body>
</html>

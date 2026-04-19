<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ auth()->user()?->theme ?? 'system' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="min-h-screen bg-neutral-950 text-neutral-100 antialiased">
    <a href="#main"
       class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-neutral-100 focus:px-3 focus:py-2 focus:text-sm focus:text-neutral-900 focus:outline-none">
        {{ __('Skip to main content') }}
    </a>

    <div class="flex min-h-screen">
        <aside class="w-60 shrink-0 border-r border-neutral-800 bg-neutral-900/50" aria-label="{{ __('Primary') }}">
            <div class="sticky top-0 flex h-screen flex-col">
                <div class="border-b border-neutral-800 px-5 py-4">
                    <div class="text-base font-semibold tracking-tight">{{ __('Bureau') }}</div>
                    @auth
                        @php $h = \App\Support\CurrentHousehold::get(); @endphp
                        @if ($h)
                            <div class="mt-0.5 text-xs text-neutral-500">{{ $h->name }}</div>
                        @endif
                    @endauth
                </div>
                <nav class="flex-1 overflow-y-auto px-3 py-4 text-sm" aria-label="{{ __('Main navigation') }}">
                    @php
                        $sections = [
                            null => [
                                [__('Dashboard'), 'dashboard', 'home'],
                                [__('Weekly review'), 'review', 'check-square'],
                            ],
                            __('Money') => [
                                [__('Overview'), 'fiscal.overview', 'pie'],
                                [__('Accounts'), 'fiscal.accounts', 'wallet'],
                                [__('Transactions'), 'fiscal.transactions', 'swap'],
                                [__('Bills & Income'), 'fiscal.recurring', 'receipt'],
                                [__('Bookkeeper'), 'bookkeeper', 'file-signature'],
                            ],
                            __('Life') => [
                                [__('Calendar'), 'calendar.index', 'calendar'],
                                [__('Tasks'), 'calendar.tasks', 'check-square'],
                                [__('Meetings'), 'calendar.meetings', 'calendar'],
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
                                [__('Properties'), 'assets.properties', 'building'],
                                [__('Vehicles'), 'assets.vehicles', 'car'],
                                [__('Inventory'), 'assets.inventory', 'box'],
                            ],
                            __('Records') => [
                                [__('Documents'), 'records.documents', 'file-text'],
                                [__('Media'), 'records.media', 'image'],
                                [__('Notes'), 'records.notes', 'note'],
                                [__('Online accounts'), 'records.online_accounts', 'key'],
                                [__('In case of'), 'records.in_case_of', 'shield'],
                                [__('Tags'), 'tags.index', 'note'],
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
                </nav>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-14 items-center justify-between border-b border-neutral-800 px-6">
                <div>
                    <h1 class="text-sm font-medium text-neutral-300">{{ $title ?? __('Dashboard') }}</h1>
                </div>
                <div class="flex items-center gap-3">
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
            <main id="main" tabindex="-1" class="flex-1 p-6">
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

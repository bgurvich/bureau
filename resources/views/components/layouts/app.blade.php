<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
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
                                [__('Dashboard'), 'dashboard'],
                            ],
                            __('Money') => [
                                [__('Accounts'), 'fiscal.accounts'],
                                [__('Transactions'), 'fiscal.transactions'],
                                [__('Bills & Income'), 'fiscal.recurring'],
                            ],
                            __('Life') => [
                                [__('Tasks'), 'calendar.tasks'],
                                [__('Meetings'), 'calendar.meetings'],
                                [__('Contacts'), 'relationships.contacts'],
                            ],
                            __('Time') => [
                                [__('Projects'), 'time.projects'],
                                [__('Time entries'), 'time.entries'],
                            ],
                            __('Commitments') => [
                                [__('Contracts'), 'relationships.contracts'],
                                [__('Insurance'), 'relationships.insurance'],
                            ],
                            __('Assets') => [
                                [__('Properties'), 'assets.properties'],
                                [__('Vehicles'), 'assets.vehicles'],
                                [__('Inventory'), 'assets.inventory'],
                            ],
                            __('Records') => [
                                [__('Documents'), 'records.documents'],
                                [__('Media'), 'records.media'],
                                [__('Notes'), 'records.notes'],
                            ],
                            __('Health') => [
                                [__('Providers'), 'health.providers'],
                                [__('Prescriptions'), 'health.prescriptions'],
                                [__('Appointments'), 'health.appointments'],
                            ],
                        ];
                    @endphp
                    @foreach ($sections as $heading => $items)
                        @if ($heading)
                            <div class="mt-4 mb-1 px-3 text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ $heading }}</div>
                        @endif
                        @foreach ($items as [$label, $route])
                            @php $active = request()->routeIs($route); @endphp
                            <a href="{{ route($route) }}"
                               @if($active) aria-current="page" @endif
                               class="flex items-center rounded-md px-3 py-1.5 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300
                                      {{ $active ? 'bg-neutral-800 text-neutral-50' : 'text-neutral-400 hover:bg-neutral-800/60 hover:text-neutral-100' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    @endforeach
                </nav>
                @auth
                    <div class="border-t border-neutral-800 px-4 py-3 text-sm">
                        <div class="flex items-center justify-between">
                            <div class="min-w-0">
                                <div class="truncate text-neutral-200">{{ auth()->user()->name }}</div>
                                <div class="truncate text-xs text-neutral-500">{{ auth()->user()->email }}</div>
                            </div>
                            <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                                @csrf
                                <button type="submit"
                                        class="rounded-md px-2 py-1 text-xs text-neutral-400 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    {{ __('Sign out') }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endauth
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-14 items-center justify-between border-b border-neutral-800 px-6">
                <div>
                    <h1 class="text-sm font-medium text-neutral-300">{{ $title ?? __('Dashboard') }}</h1>
                </div>
                <div class="flex items-center gap-4">
                    @auth
                        <livewire:time-tracker />
                    @endauth
                    <time class="text-xs text-neutral-500 tabular-nums" datetime="{{ now()->toIso8601String() }}">
                        {{ now()->format('D · M j · Y') }}
                    </time>
                </div>
            </header>
            <main id="main" tabindex="-1" class="flex-1 p-6">
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>

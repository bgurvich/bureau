<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dusk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? __('Bookkeeper portal') }}</title>
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    @include('partials.theme-flash')
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="min-h-screen bg-neutral-950 text-neutral-100 antialiased">
    {{-- Deliberately minimal shell: no sidebar, no alerts bell, no
         global search. This layout is ONLY reachable from a valid
         portal grant session, so a CPA can't accidentally fall into
         an owner-only surface via a stray link. --}}
    <header class="border-b border-neutral-800 bg-neutral-900/40">
        <div class="mx-auto flex max-w-5xl items-baseline justify-between px-5 py-3">
            <div>
                <div class="text-sm font-semibold text-neutral-100">{{ __('Secretaire') }}</div>
                <div class="text-[11px] text-neutral-500">{{ __('Bookkeeper portal — read-only') }}</div>
            </div>
        </div>
    </header>
    <main class="mx-auto max-w-5xl px-5 py-6">
        {{ $slot }}
    </main>
</body>
</html>

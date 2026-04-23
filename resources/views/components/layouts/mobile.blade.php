<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ auth()->user()?->theme ?? 'dusk' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Browser-chrome tint — static value so the meta tag works even when
         JS is down. Dusk page bg (neutral-950) sits around a warm stone
         #d8cfbf; kept in sync if the palette shifts. --}}
    <meta name="theme-color" content="#d8cfbf">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Secretaire">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <title>{{ $title ?? 'Secretaire' }}</title>
    @include('partials.theme-flash')
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="min-h-screen bg-neutral-950 text-neutral-100 antialiased">
    <div class="mobile-shell flex min-h-screen flex-col">
        <main id="main" tabindex="-1" class="flex-1 px-4 py-4">
            {{ $slot }}
        </main>
    </div>

    @include('partials.mobile-tabs')

    @auth
        {{-- Inspector drawer + modal-stack drawer available on mobile too
             so Capture-screen tiles can dispatch `inspector-open` to log
             a journal/decision/reading/food entry inline without routing
             to a dedicated screen per type. --}}
        <livewire:inspector />
        <livewire:inspector :as-modal="true" key="inspector-modal" />
    @endauth
</body>
</html>

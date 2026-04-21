<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ auth()->user()?->theme ?? 'system' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0a0a0a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Bureau">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <title>{{ $title ?? 'Bureau' }}</title>
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

</body>
</html>

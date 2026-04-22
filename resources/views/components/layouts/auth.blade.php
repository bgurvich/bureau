<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dusk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @include('partials.theme-flash')
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="min-h-screen bg-neutral-950 text-neutral-100 antialiased">
    <main class="flex min-h-screen items-center justify-center p-6">
        <div class="w-full max-w-sm">
            <div class="mb-8 text-center">
                <div class="text-xl font-semibold tracking-tight">{{ __('Bureau') }}</div>
                <p class="mt-1 text-xs text-neutral-500">{{ __('Personal affairs, in one place.') }}</p>
            </div>
            {{ $slot }}
        </div>
    </main>
</body>
</html>

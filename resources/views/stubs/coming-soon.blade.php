<x-layouts.app :title="$title ?? 'Coming soon'">
    <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center">
        <h2 class="text-lg font-medium text-neutral-200">{{ $title ?? 'Coming soon' }}</h2>
        <p class="mx-auto mt-2 max-w-md text-sm text-neutral-500">
            {{ $body ?? 'This domain has its schema in place and a seat reserved in the dashboard. The drill-down view lands in the next build.' }}
        </p>
        <a href="{{ route('dashboard') }}" class="mt-6 inline-block text-sm text-neutral-400 hover:text-neutral-200">← Back to dashboard</a>
    </div>
</x-layouts.app>

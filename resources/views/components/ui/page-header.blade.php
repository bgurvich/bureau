@props([
    'title',
    'description' => null,
])

{{-- Standard index-page header: title + optional description on the left,
     free slot on the right for one or more action buttons. Extracted so
     all index pages converge on the same font size/weight, spacing, and
     baseline alignment. --}}
<header class="flex items-baseline justify-between gap-4">
    <div>
        <h2 class="text-base font-semibold text-neutral-100">{{ $title }}</h2>
        @if ($description)
            <p class="mt-1 text-xs text-neutral-500">{{ $description }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex items-center gap-3">
            {{ $slot }}
        </div>
    @endif
</header>

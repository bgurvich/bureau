@props([
    'tags' => null,
    'active' => null,
])

@if ($tags && count($tags))
    <div {{ $attributes->class(['flex flex-wrap gap-1']) }}>
        @foreach ($tags as $tag)
            @php
                $isActive = $active !== null && $active === ($tag->slug ?? null);
                $cls = $isActive
                    ? 'bg-emerald-900/40 text-emerald-200'
                    : 'bg-neutral-800 text-neutral-300 hover:bg-neutral-700';
            @endphp
            @if ($tag->slug ?? null)
                <a href="{{ route('tags.show', $tag->slug) }}"
                   wire:click.stop
                   x-on:click.stop
                   class="shrink-0 rounded px-1.5 py-0.5 font-mono text-[10px] {{ $cls }} focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">#{{ $tag->name }}</a>
            @else
                <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-[10px] {{ $cls }}">#{{ $tag->name }}</span>
            @endif
        @endforeach
    </div>
@endif

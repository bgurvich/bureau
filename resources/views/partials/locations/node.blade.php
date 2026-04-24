@php
    $indentStyle = $depth > 0 ? 'padding-left: '.(1 + $depth * 1.5).'rem' : '';
    $kids = $children[$node->id] ?? collect();
    $count = $counts[$node->id] ?? 0;
@endphp
<li class="group relative flex items-baseline gap-3 px-4 py-2.5 text-sm transition hover:bg-neutral-800/30"
    style="{{ $indentStyle }}">
    @if ($depth > 0)
        <span aria-hidden="true"
              class="absolute top-0 bottom-0 w-px bg-neutral-800"
              style="left: {{ 1 + ($depth - 1) * 1.5 + 0.6 }}rem"></span>
    @endif
    <span class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider {{ $kindBadge($node->kind) }}">
        {{ $node->kind }}
    </span>
    <button type="button"
            wire:click="$dispatch('inspector-open', { type: 'location', id: {{ $node->id }} })"
            class="flex-1 min-w-0 truncate text-left text-neutral-100 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        {{ $node->name }}
    </button>
    @if ($count > 0)
        <span class="shrink-0 text-[11px] tabular-nums text-neutral-500">{{ __(':n items', ['n' => $count]) }}</span>
    @endif
    <button type="button"
            x-data
            x-on:click.stop="$dispatch('inspector-open', { type: 'location', id: null, parentId: {{ $node->id }} })"
            aria-label="{{ __('Add child location') }}"
            title="{{ __('Add child') }}"
            class="shrink-0 rounded-md border border-neutral-800 bg-neutral-900/80 px-1.5 py-0.5 text-[10px] text-neutral-400 opacity-0 transition group-hover:opacity-100 hover:text-neutral-100 focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        + {{ __('child') }}
    </button>
</li>
@foreach ($kids as $kid)
    @include('partials.locations.node', ['node' => $kid, 'depth' => $depth + 1, 'children' => $children, 'counts' => $counts, 'kindBadge' => $kindBadge])
@endforeach

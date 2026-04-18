@props([
    'column',
    'label',
    'sortBy',
    'sortDir',
    'method' => 'sort',
    'align' => 'left',
])

@php
    $isActive = $sortBy === $column;
    $nextDir = $isActive && $sortDir === 'asc' ? 'desc' : 'asc';
    $alignClass = $align === 'right' ? 'justify-end' : 'justify-start';
@endphp

<th scope="col" class="px-3 py-2 text-{{ $align }} font-medium">
    <button type="button"
            wire:click="{{ $method }}('{{ $column }}')"
            aria-sort="{{ $isActive ? ($sortDir === 'asc' ? 'ascending' : 'descending') : 'none' }}"
            class="inline-flex items-center gap-1 uppercase tracking-wider text-[10px] transition hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $isActive ? 'text-neutral-200' : 'text-neutral-500' }} {{ $alignClass }}">
        <span>{{ $label }}</span>
        <span aria-hidden="true" class="inline-flex h-3 w-3 items-center justify-center {{ $isActive ? 'opacity-100' : 'opacity-30' }}">
            @if ($isActive && $sortDir === 'asc')
                <svg viewBox="0 0 12 12" class="h-2.5 w-2.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m3 7 3-3 3 3"/>
                </svg>
            @else
                <svg viewBox="0 0 12 12" class="h-2.5 w-2.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m3 5 3 3 3-3"/>
                </svg>
            @endif
        </span>
    </button>
</th>

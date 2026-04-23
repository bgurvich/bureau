@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';
@endphp

{{-- Secretaire-tuned pagination view.
     Diff vs Livewire's default:
       * Dark-theme palette baked in (neutral-900/950 chrome, neutral-200 text)
       * CURRENT PAGE is the visually dominant element — filled neutral-100 bg + neutral-900 text.
         The default shipped current page with *lower-contrast* grey text on the same
         background as the other links, which read as less-prominent than siblings.
       * Other links use subtle borders; active/hover states bump contrast rather than
         swapping palette so the hit target stays visually stable. --}}
<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="{{ __('Pagination') }}" class="flex items-center justify-between gap-3 text-xs">
            {{-- Mobile: prev / next only --}}
            <div class="flex flex-1 justify-between sm:hidden">
                @if ($paginator->onFirstPage())
                    <span class="inline-flex items-center rounded-md border border-neutral-800 bg-neutral-950 px-3 py-1.5 text-neutral-600">{!! __('pagination.previous') !!}</span>
                @else
                    <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                            class="inline-flex items-center rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-neutral-200 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        {!! __('pagination.previous') !!}
                    </button>
                @endif

                @if ($paginator->hasMorePages())
                    <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                            class="ml-3 inline-flex items-center rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-neutral-200 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        {!! __('pagination.next') !!}
                    </button>
                @else
                    <span class="ml-3 inline-flex items-center rounded-md border border-neutral-800 bg-neutral-950 px-3 py-1.5 text-neutral-600">{!! __('pagination.next') !!}</span>
                @endif
            </div>

            {{-- Desktop: "Showing X to Y of Z" + numbered pager --}}
            <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                <p class="text-[11px] text-neutral-500">
                    {!! __('Showing') !!}
                    <span class="tabular-nums text-neutral-300">{{ $paginator->firstItem() }}</span>
                    {!! __('to') !!}
                    <span class="tabular-nums text-neutral-300">{{ $paginator->lastItem() }}</span>
                    {!! __('of') !!}
                    <span class="tabular-nums text-neutral-300">{{ $paginator->total() }}</span>
                </p>

                <div>
                    <span class="inline-flex items-center gap-1">
                        {{-- Prev --}}
                        @if ($paginator->onFirstPage())
                            <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}"
                                  class="inline-flex items-center rounded-md border border-neutral-800 bg-neutral-950 px-2 py-1 text-neutral-600">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                            </span>
                        @else
                            <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                    aria-label="{{ __('pagination.previous') }}"
                                    class="inline-flex items-center rounded-md border border-neutral-700 bg-neutral-900 px-2 py-1 text-neutral-300 hover:bg-neutral-800 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        @endif

                        @foreach ($elements as $element)
                            @if (is_string($element))
                                <span aria-disabled="true"
                                      class="inline-flex items-center px-2 py-1 text-neutral-600">{{ $element }}</span>
                            @endif

                            @if (is_array($element))
                                @foreach ($element as $page => $url)
                                    <span wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}">
                                        @if ($page == $paginator->currentPage())
                                            <span aria-current="page"
                                                  class="inline-flex items-center rounded-md border border-neutral-100 bg-neutral-100 px-3 py-1 text-xs font-semibold text-neutral-900 tabular-nums shadow-sm">
                                                {{ $page }}
                                            </span>
                                        @else
                                            <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                                    aria-label="{{ __('Go to page :page', ['page' => $page]) }}"
                                                    class="inline-flex items-center rounded-md border border-neutral-800 bg-neutral-900 px-3 py-1 text-xs text-neutral-400 tabular-nums hover:border-neutral-700 hover:bg-neutral-800 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                                {{ $page }}
                                            </button>
                                        @endif
                                    </span>
                                @endforeach
                            @endif
                        @endforeach

                        {{-- Next --}}
                        @if ($paginator->hasMorePages())
                            <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                    aria-label="{{ __('pagination.next') }}"
                                    class="inline-flex items-center rounded-md border border-neutral-700 bg-neutral-900 px-2 py-1 text-neutral-300 hover:bg-neutral-800 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        @else
                            <span aria-disabled="true" aria-label="{{ __('pagination.next') }}"
                                  class="inline-flex items-center rounded-md border border-neutral-800 bg-neutral-950 px-2 py-1 text-neutral-600">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                </svg>
                            </span>
                        @endif
                    </span>
                </div>
            </div>
        </nav>
    @endif
</div>

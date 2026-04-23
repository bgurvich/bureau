{{-- Polymorphic subjects: draggable ordered chip list + keyboard-navigable --}}
{{-- search-on-type dropdown.                                              --}}
{{-- State: subject_refs (array), subject_search (string).                 --}}
{{-- Methods: addSubject, removeSubject, reorderSubjects (moveSubjectTo    --}}
{{-- is kept as a single-ref API for external callers/tests).              --}}
<div>
    <label class="mb-1 block text-xs text-neutral-400">{{ __('Linked to') }}</label>

    {{-- Selected chip list, in order. Drag to reorder. --}}
    @if ($subject_refs === [])
        <p class="mb-2 text-[11px] text-neutral-500">{{ __('No links yet. Search below to attach vehicles, properties, contracts, etc.') }}</p>
    @else
        <x-ui.sortable-list reorder-method="reorderSubjects" class="mb-2 space-y-1" data-testid="subject-chips">
            @foreach ($subject_refs as $ref)
                @php($meta = $this->selectedSubjectsMeta[$ref] ?? ['label' => $ref, 'kind_label' => ''])
                @php($parts = explode(':', $ref, 2))
                @php($editKind = $parts[0] ?? '')
                @php($editId = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : null)
                {{-- SUBJECT_KIND_MAP kinds → inspector-shell type names.
                     Most match 1:1; 'recurring_rule' shows in the shell as 'bill',
                     and health_provider has no inspector form so we hide the
                     pencil (map = null → rendered as !$editInspectorType). --}}
                @php($editInspectorType = match ($editKind) {
                    'recurring_rule' => 'bill',
                    'health_provider' => null,
                    default => $editKind,
                })
                <x-ui.sortable-row :item-key="$ref" :no-handle="true"
                                   class="flex cursor-grab items-center gap-2 rounded-md border border-neutral-800 bg-neutral-900/60 px-2 py-1 text-xs text-neutral-100 select-none">
                    <svg class="h-3 w-3 shrink-0 text-neutral-500" viewBox="0 0 12 16" aria-hidden="true" fill="currentColor">
                        <circle cx="3" cy="3" r="1.2"/><circle cx="3" cy="8" r="1.2"/><circle cx="3" cy="13" r="1.2"/>
                        <circle cx="9" cy="3" r="1.2"/><circle cx="9" cy="8" r="1.2"/><circle cx="9" cy="13" r="1.2"/>
                    </svg>
                    <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">
                        {{ $meta['kind_label'] }}
                    </span>
                    <span class="truncate">{{ Str::after($meta['label'], ' · ') ?: $meta['label'] }}</span>
                    @if ($editId !== null && $editInspectorType !== null)
                        {{-- Pencil → modal inspector for the linked subject. Stops propagation
                             so clicking it doesn't trigger drag-start on the cursor-grab row. --}}
                        <button type="button"
                                x-data
                                x-on:click.stop="$dispatch('subentity-edit-open', { type: @js($editInspectorType), id: {{ $editId }} })"
                                aria-label="{{ __('Edit linked item') }}"
                                title="{{ __('Edit linked item') }}"
                                class="ml-auto shrink-0 rounded px-1.5 py-0.5 text-neutral-400 hover:bg-neutral-800 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <svg class="h-3 w-3" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <path d="M2 12.5V14h1.5l8.373-8.373-1.5-1.5L2 12.5zM13.707 3.793a1 1 0 0 0 0-1.414l-1.086-1.086a1 1 0 0 0-1.414 0l-1.293 1.293 2.5 2.5 1.293-1.293z" fill="currentColor"/>
                            </svg>
                        </button>
                    @endif
                    <button type="button"
                            wire:click="removeSubject('{{ $ref }}')"
                            aria-label="{{ __('Remove') }}"
                            class="{{ $editInspectorType !== null && $editId !== null ? '' : 'ml-auto ' }}shrink-0 rounded px-1.5 py-0.5 text-[11px] text-rose-400 hover:bg-rose-900/30 hover:text-rose-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        ×
                    </button>
                </x-ui.sortable-row>
            @endforeach
        </x-ui.sortable-list>
    @endif

    {{-- Search input + keyboard-navigable dropdown --}}
    <div class="relative"
         x-data="{
             open: false,
             focusIndex: -1,
             resultCount() {
                 return ($refs.results?.querySelectorAll('[data-result]') ?? []).length;
             },
             focusResult(idx) {
                 const btns = $refs.results?.querySelectorAll('[data-result]');
                 if (btns && btns[idx]) btns[idx].scrollIntoView({ block: 'nearest' });
                 this.focusIndex = idx;
             },
         }"
         x-on:click.outside="open = false">
        <input type="text"
               wire:model.live.debounce.250ms="subject_search"
               x-on:focus="open = true"
               x-on:keydown.arrow-down.prevent="
                   open = true;
                   const n = resultCount();
                   if (n > 0) focusResult(Math.min(focusIndex + 1, n - 1));
               "
               x-on:keydown.arrow-up.prevent="
                   const n = resultCount();
                   if (n > 0) focusResult(Math.max(focusIndex - 1, 0));
               "
               x-on:keydown.enter.prevent="
                   if (focusIndex >= 0) {
                       const btn = $refs.results?.querySelector(`[data-idx='${focusIndex}']`);
                       if (btn) btn.click();
                   }
               "
               x-on:keydown.escape="open = false; focusIndex = -1"
               x-effect="
                   $watch('$wire.subject_search', () => { focusIndex = -1; });
               "
               placeholder="{{ __('Search vehicles, properties, contracts…') }}"
               aria-label="{{ __('Search subjects to link') }}"
               role="combobox"
               aria-autocomplete="list"
               aria-expanded="open"
               autocomplete="off"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">

        @if (trim($subject_search) !== '' && mb_strlen(trim($subject_search)) >= 2)
            <ul x-show="open"
                x-ref="results"
                class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-md border border-neutral-800 bg-neutral-950 py-1 text-xs shadow-xl"
                role="listbox">
                @forelse ($this->subjectSearchResults as $idx => $hit)
                    <li data-result>
                        <button type="button"
                                data-idx="{{ $idx }}"
                                wire:click="addSubject('{{ $hit['ref'] }}')"
                                x-on:mouseenter="focusIndex = {{ $idx }}"
                                x-bind:class="focusIndex === {{ $idx }} ? 'bg-neutral-800/70 text-neutral-50' : 'text-neutral-200'"
                                class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-neutral-800/60 focus-visible:bg-neutral-800/60 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-neutral-300">
                            <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">
                                {{ $hit['kind_label'] }}
                            </span>
                            <span class="truncate">{{ $hit['name'] }}</span>
                        </button>
                    </li>
                @empty
                    <li class="px-3 py-1.5 text-neutral-500">{{ __('No matches.') }}</li>
                @endforelse
            </ul>
        @endif
    </div>
</div>

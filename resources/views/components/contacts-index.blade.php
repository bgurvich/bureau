<?php

use App\Models\Contact;
use App\Support\ContactMerge;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Contacts'])]
class extends Component
{
    #[Url(as: 'kind')]
    public string $kindFilter = '';

    #[Url(as: 'role')]
    public string $roleFilter = '';

    /**
     * When true, filter down to Contacts that aren't referenced from
     * any domain table (transactions, accounts, pivots, morphs). A
     * vendor re-resolve often strands old auto-created contacts —
     * this filter surfaces them for bulk delete / merge.
     */
    #[Url(as: 'orphaned')]
    public bool $orphanedOnly = false;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'sort')]
    public string $sortBy = 'display_name';

    #[Url(as: 'dir')]
    public string $sortDir = 'asc';

    public bool $favoritesOnly = false;

    /** @var array<int, int> ids selected for bulk actions */
    public array $selected = [];

    /** Merge modal state. Winner receives everything; losers are deleted. */
    public bool $showMerge = false;

    public ?int $mergeWinnerId = null;

    public string $mergeWinnerName = '';

    public ?string $mergeMessage = null;

    public function sort(string $column): void
    {
        if (! in_array($column, ['display_name', 'organization', 'kind'], true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    public function toggleSelect(int $id): void
    {
        if (in_array($id, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$id]));
        } else {
            $this->selected[] = $id;
        }
    }

    public function selectAllVisible(): void
    {
        $this->selected = $this->contacts->pluck('id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function deleteSelected(): void
    {
        $ids = array_values(array_filter(array_map('intval', $this->selected)));
        if (empty($ids)) {
            return;
        }

        Contact::whereIn('id', $ids)->delete();
        $this->selected = [];
        unset($this->contacts);
    }

    /**
     * Open the merge modal. Default winner = first selected (stable);
     * the user can pick any of the selected contacts instead, then edit
     * the winner's display_name before confirming.
     */
    public function openMerge(): void
    {
        $ids = array_values(array_filter(array_map('intval', $this->selected)));
        if (count($ids) < 2) {
            return;
        }

        $winner = Contact::whereIn('id', $ids)->orderByDesc('favorite')->orderBy('id')->first();
        if (! $winner) {
            return;
        }

        $this->mergeWinnerId = (int) $winner->id;
        $this->mergeWinnerName = (string) $winner->display_name;
        $this->mergeMessage = null;
        $this->showMerge = true;
    }

    public function closeMerge(): void
    {
        $this->showMerge = false;
        $this->mergeWinnerId = null;
        $this->mergeWinnerName = '';
        $this->mergeMessage = null;
    }

    /**
     * When the user switches which contact survives, re-prefill the name
     * field with the new winner's display_name so the default tracks the
     * radio. Custom text the user already typed is overwritten — the
     * field's purpose is "the final name"; the helper text makes clear
     * that any value is accepted.
     */
    public function updatedMergeWinnerId(int|string|null $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $winner = Contact::find((int) $value);
        if ($winner) {
            $this->mergeWinnerName = (string) $winner->display_name;
        }
    }

    /**
     * Confirm the merge. Runs ContactMerge for each loser against the
     * chosen winner, then applies the user-edited display_name to the
     * survivor. Everything the losers pointed at is now on the winner.
     */
    public function confirmMerge(): void
    {
        $ids = array_values(array_filter(array_map('intval', $this->selected)));
        if (count($ids) < 2 || ! $this->mergeWinnerId || ! in_array($this->mergeWinnerId, $ids, true)) {
            $this->mergeMessage = __('Pick which contact should survive.');

            return;
        }

        $winner = Contact::find($this->mergeWinnerId);
        if (! $winner) {
            $this->mergeMessage = __('Winner not found.');

            return;
        }

        $losers = Contact::whereIn('id', array_diff($ids, [$this->mergeWinnerId]))->get();
        foreach ($losers as $loser) {
            ContactMerge::run($winner, $loser);
        }

        $newName = trim($this->mergeWinnerName);
        if ($newName !== '' && $newName !== $winner->display_name) {
            $winner->forceFill(['display_name' => $newName])->save();
        }

        $this->selected = [];
        $this->closeMerge();
        unset($this->contacts);
    }

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->contacts);
    }

    #[Computed]
    public function contacts(): Collection
    {
        $sortColumn = in_array($this->sortBy, ['display_name', 'organization', 'kind'], true)
            ? $this->sortBy
            : 'display_name';

        return Contact::query()
            ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter))
            ->when($this->roleFilter === 'vendor', fn ($q) => $q->where('is_vendor', true))
            ->when($this->roleFilter === 'customer', fn ($q) => $q->where('is_customer', true))
            ->when($this->roleFilter === 'both', fn ($q) => $q->where('is_vendor', true)->where('is_customer', true))
            ->when($this->orphanedOnly, function ($q) {
                $referenced = ContactMerge::referencedContactIds();

                return $referenced === []
                    ? $q
                    : $q->whereNotIn('id', $referenced);
            })
            ->when($this->favoritesOnly, fn ($q) => $q->where('favorite', true))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('display_name', 'like', $term)
                    ->orWhere('organization', 'like', $term)
                    ->orWhere('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                );
            })
            ->orderByDesc('favorite')
            ->orderBy($sortColumn, $this->sortDir)
            ->orderBy('id')
            ->limit(500)
            ->get();
    }

    /**
     * Rows the merge modal needs to display — same shape the user just
     * selected, fetched once so the modal doesn't re-query per radio.
     *
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function selectedContacts(): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $this->selected)));
        if (empty($ids)) {
            return new Collection;
        }

        return Contact::whereIn('id', $ids)->orderBy('display_name')->get();
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Contacts') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('People and organizations you deal with.') }}</p>
        </div>
        <x-ui.new-record-button type="contact" :label="__('New contact')" shortcut="C" />
    </header>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="c-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="c-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Name or organization…') }}">
        </div>
        <div>
            <label for="c-kind" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Kind') }}</label>
            <select wire:model.live="kindFilter" id="c-kind"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (App\Support\Enums::contactKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="c-role" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Role') }}</label>
            <select wire:model.live="roleFilter" id="c-role"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('Any') }}</option>
                <option value="vendor">{{ __('Vendor') }}</option>
                <option value="customer">{{ __('Customer') }}</option>
                <option value="both">{{ __('Both') }}</option>
            </select>
        </div>
        <label class="flex items-center gap-2 text-xs text-neutral-300"
               title="{{ __('Contacts with no transactions, accounts, contracts, or other references. Safe to bulk-delete after a vendor re-resolve.') }}">
            <input wire:model.live="orphanedOnly" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Orphaned only') }}
        </label>
        <label class="flex items-center gap-2 text-xs text-neutral-300">
            <input wire:model.live="favoritesOnly" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Favorites only') }}
        </label>
    </form>

    @if ($this->contacts->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No contacts match those filters.') }}
        </div>
    @else
        @php
            $selCount = count($selected);
            $visibleCount = $this->contacts->count();
            $visibleIds = $this->contacts->pluck('id')->map(fn ($v) => (int) $v)->all();
            $visibleSel = array_intersect($selected, $visibleIds);
            $allChecked = $visibleCount > 0 && count($visibleSel) === $visibleCount;
            $someChecked = count($visibleSel) > 0 && count($visibleSel) !== $visibleCount;
        @endphp
        <section aria-label="{{ __('Contacts list') }}">
        <div role="region" aria-label="{{ __('List header') }}"
             class="sticky top-0 z-10 rounded-t-xl border border-b-0 {{ $selCount > 0 ? 'border-amber-800/50 bg-amber-950/30' : 'border-neutral-800 bg-neutral-900/60' }} px-4 py-2 text-[11px]">
            <div class="flex min-h-8 flex-wrap items-center gap-3">
                <input type="checkbox"
                       wire:click="{{ $allChecked ? 'clearSelection' : 'selectAllVisible' }}"
                       @checked($allChecked)
                       x-bind:indeterminate="{{ $someChecked ? 'true' : 'false' }}"
                       aria-label="{{ __('Select all') }}"
                       class="rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <span class="{{ $selCount > 0 ? 'text-amber-100' : 'text-neutral-400' }}">
                    @if ($selCount > 0)
                        {{ __(':sel of :n selected', ['sel' => $selCount, 'n' => $visibleCount]) }}
                    @else
                        {{ __(':n contacts', ['n' => $visibleCount]) }}
                    @endif
                </span>
                @if ($selCount > 0)
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <button type="button"
                                wire:click="openMerge"
                                @disabled($selCount < 2)
                                data-testid="contacts-bulk-merge"
                                class="rounded-md border border-emerald-800/60 bg-emerald-900/30 px-3 py-1 text-xs text-emerald-100 hover:bg-emerald-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 disabled:cursor-not-allowed disabled:opacity-50">
                            {{ __('Merge selected') }}
                        </button>
                        <button type="button"
                                wire:click="deleteSelected"
                                wire:confirm="{{ __('Delete the :n selected contact(s)? This cannot be undone.', ['n' => $selCount]) }}"
                                data-testid="contacts-bulk-delete"
                                class="rounded-md border border-rose-800/50 bg-rose-900/30 px-3 py-1 text-xs font-medium text-rose-100 hover:bg-rose-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Delete selected') }}
                        </button>
                        <button type="button" wire:click="clearSelection"
                                class="rounded-md px-3 py-1 text-xs text-amber-200 hover:bg-amber-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Clear') }}
                        </button>
                    </div>
                @endif
            </div>
        </div>

        <div class="overflow-x-auto rounded-b-xl border border-neutral-800 bg-neutral-900/40">
            <table class="w-full min-w-[48rem] text-sm">
                <thead class="border-b border-neutral-800 text-left">
                    <tr>
                        <th scope="col" class="w-10 px-3 py-2"><span class="sr-only">{{ __('Select') }}</span></th>
                        <x-ui.sortable-header column="display_name" :label="__('Name')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-ui.sortable-header column="kind" :label="__('Kind')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-ui.sortable-header column="organization" :label="__('Organization')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <th scope="col" class="px-3 py-2 text-left text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Role') }}</th>
                        <th scope="col" class="px-3 py-2 text-left text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Contact') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800/60">
                    @foreach ($this->contacts as $c)
                        @php($isSelected = in_array($c->id, $selected, true))
                        <tr wire:key="c-row-{{ $c->id }}"
                            class="transition {{ $isSelected ? 'bg-amber-950/20' : 'hover:bg-neutral-800/30' }}">
                            <td class="px-3 py-2">
                                <input type="checkbox"
                                       wire:click="toggleSelect({{ $c->id }})"
                                       @checked($isSelected)
                                       aria-label="{{ __('Select :name', ['name' => $c->display_name]) }}"
                                       class="rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            </td>
                            <td class="px-3 py-2">
                                <button type="button"
                                        wire:click="$dispatch('inspector-open', { type: 'contact', id: {{ $c->id }} })"
                                        class="flex items-center gap-2 text-left text-neutral-100 hover:text-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    <span aria-hidden="true" class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-neutral-800 text-xs text-neutral-300">
                                        {{ strtoupper(mb_substr($c->display_name, 0, 1)) }}
                                    </span>
                                    <span class="truncate">{{ $c->display_name }}</span>
                                    @if ($c->favorite)
                                        <span aria-label="{{ __('Favorite') }}" class="text-amber-400">★</span>
                                    @endif
                                </button>
                            </td>
                            <td class="px-3 py-2 text-[11px] uppercase tracking-wider text-neutral-400">{{ $c->kind }}</td>
                            <td class="px-3 py-2 text-xs text-neutral-400">{{ $c->organization ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-1 text-[10px] uppercase tracking-wider">
                                    @if ($c->is_vendor)
                                        <span class="rounded bg-neutral-800 px-1.5 py-0.5 text-neutral-300">{{ __('Vendor') }}</span>
                                    @endif
                                    @if ($c->is_customer)
                                        <span class="rounded bg-neutral-800 px-1.5 py-0.5 text-neutral-300">{{ __('Customer') }}</span>
                                    @endif
                                    @if (! $c->is_vendor && ! $c->is_customer)
                                        <span class="text-neutral-600">—</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-3 py-2 text-xs text-neutral-400">
                                @if (is_array($c->emails) && count($c->emails))
                                    <span class="truncate">{{ $c->emails[0] }}</span>
                                @elseif (is_array($c->phones) && count($c->phones))
                                    <span class="tabular-nums">{{ $c->phones[0] }}</span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </section>
    @endif

    @if ($showMerge)
        <div x-cloak x-transition.opacity
             class="fixed inset-0 z-40 bg-black/60"
             wire:click="closeMerge"
             aria-hidden="true"></div>

        <aside x-cloak
               x-data
               x-on:keydown.escape.window="$wire.closeMerge()"
               role="dialog" aria-modal="true" aria-label="{{ __('Merge contacts') }}"
               class="fixed left-1/2 top-24 z-50 w-full max-w-lg -translate-x-1/2 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-950 shadow-2xl">
            <header class="flex items-center justify-between border-b border-neutral-800 px-5 py-3">
                <h2 class="text-sm font-semibold text-neutral-100">{{ __('Merge :n contacts', ['n' => count($selected)]) }}</h2>
                <button type="button" wire:click="closeMerge" aria-label="{{ __('Close') }}"
                        class="rounded-md p-1 text-neutral-500 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </button>
            </header>
            <div class="space-y-4 px-5 py-4">
                <p class="text-xs text-neutral-500">
                    {{ __('Pick which contact survives. Everything attached to the others — transactions, accounts, tags, documents — moves to the survivor, then the duplicates are deleted.') }}
                </p>

                <fieldset class="space-y-2">
                    <legend class="mb-1 block text-xs text-neutral-400">{{ __('Keep this contact') }}</legend>
                    @foreach ($this->selectedContacts as $sc)
                        <label wire:key="merge-choice-{{ $sc->id }}"
                               class="flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2 text-sm transition {{ $mergeWinnerId === (int) $sc->id ? 'border-emerald-700 bg-emerald-950/30' : 'border-neutral-800 bg-neutral-900/60 hover:border-neutral-600' }}">
                            <input type="radio"
                                   wire:model.live="mergeWinnerId"
                                   name="mergeWinnerId"
                                   value="{{ $sc->id }}"
                                   class="mt-1 border-neutral-700 bg-neutral-950 text-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline gap-2">
                                    <span class="truncate text-neutral-100">{{ $sc->display_name }}</span>
                                    @if ($sc->favorite)
                                        <span aria-label="{{ __('Favorite') }}" class="text-amber-400">★</span>
                                    @endif
                                </div>
                                <div class="mt-0.5 flex flex-wrap gap-2 text-[11px] text-neutral-500">
                                    <span class="uppercase tracking-wider">{{ $sc->kind }}</span>
                                    @if ($sc->organization)
                                        <span>{{ $sc->organization }}</span>
                                    @endif
                                    @if (is_array($sc->emails) && count($sc->emails))
                                        <span>{{ $sc->emails[0] }}</span>
                                    @endif
                                </div>
                            </div>
                        </label>
                    @endforeach
                </fieldset>

                <div>
                    <label for="merge-name" class="mb-1 block text-xs text-neutral-400">{{ __('Final display name') }}</label>
                    <input wire:model="mergeWinnerName" id="merge-name" type="text"
                           list="merge-name-options"
                           autocomplete="off"
                           class="w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <datalist id="merge-name-options">
                        @foreach ($this->selectedContacts as $sc)
                            <option value="{{ $sc->display_name }}"></option>
                            @if ($sc->organization)
                                <option value="{{ $sc->organization }}"></option>
                            @endif
                        @endforeach
                    </datalist>
                    <p class="mt-1 text-[11px] text-neutral-500">{{ __('Pick one of the existing names from the list, or type a new one.') }}</p>
                </div>

                @if ($mergeMessage)
                    <div role="alert" class="rounded-md border border-rose-800/50 bg-rose-950/30 px-3 py-2 text-xs text-rose-200">
                        {{ $mergeMessage }}
                    </div>
                @endif
            </div>
            <footer class="flex items-center justify-end gap-2 border-t border-neutral-800 bg-neutral-900/50 px-5 py-3">
                <button type="button" wire:click="closeMerge"
                        class="rounded-md px-3 py-1.5 text-xs text-neutral-400 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Cancel') }}
                </button>
                <button type="button"
                        wire:click="confirmMerge"
                        data-testid="contacts-merge-confirm"
                        class="rounded-md bg-emerald-600 px-4 py-1.5 text-xs font-medium text-emerald-50 hover:bg-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <span wire:loading.remove wire:target="confirmMerge">{{ __('Merge') }}</span>
                    <span wire:loading wire:target="confirmMerge">{{ __('Merging…') }}</span>
                </button>
            </footer>
        </aside>
    @endif
</div>

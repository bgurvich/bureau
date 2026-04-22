<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.app', ['title' => 'Transactions'])]
class extends Component
{
    use WithPagination;

    #[Url(as: 'account')]
    public string $accountId = '';

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'tag')]
    public string $tagFilter = '';

    #[Url(as: 'category')]
    public string $categoryFilter = '';

    #[Url(as: 'counterparty')]
    public string $counterpartyFilter = '';

    #[Url(as: 'sort')]
    public string $sortBy = 'occurred_on';

    #[Url(as: 'dir')]
    public string $sortDir = 'desc';

    public function sort(string $column): void
    {
        if (! in_array($column, ['occurred_on', 'description', 'amount', 'status'], true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = $column === 'occurred_on' ? 'desc' : 'asc';
        }

        $this->resetPage();
    }

    public function mount(): void
    {
        if ($this->from === '') {
            $this->from = now()->subMonths(3)->toDateString();
        }
        if ($this->to === '') {
            $this->to = now()->toDateString();
        }
    }

    public function updatingAccountId(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingCounterpartyFilter(): void
    {
        $this->resetPage();
    }

    public function updatingCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['accountId', 'status', 'search', 'counterpartyFilter', 'categoryFilter', 'tagFilter']);
        $this->from = now()->subMonths(3)->toDateString();
        $this->to = now()->toDateString();
        $this->resetPage();
    }

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->transactions, $this->totals);
    }

    // ── Bulk-select state ──────────────────────────────────────────

    /** @var array<int, int> Transaction ids the user has checked. */
    public array $selected = [];

    /** Bulk-edit modal state — one shared modal serves all edit actions. */
    public bool $showBulkEdit = false;

    public ?int $bulkCategoryId = null;

    public ?int $bulkCounterpartyId = null;

    public string $bulkTagsToAdd = '';

    public ?string $bulkMessage = null;

    public function toggleRow(int $id): void
    {
        if (in_array($id, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$id]));
        } else {
            $this->selected[] = $id;
        }
    }

    public function selectAllVisible(): void
    {
        $ids = $this->transactions->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->selected = array_values(array_unique(array_merge($this->selected, $ids)));
    }

    /** Drop only the visible page from the selection; cross-page picks stay. */
    public function deselectAllVisible(): void
    {
        $visible = $this->transactions->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->selected = array_values(array_diff($this->selected, $visible));
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    private function setSelectedStatus(string $status): int
    {
        if (! in_array($status, ['pending', 'cleared', 'reconciled'], true) || $this->selected === []) {
            return 0;
        }

        $ids = array_values(array_filter(array_map('intval', $this->selected)));

        return Transaction::whereIn('id', $ids)->update(['status' => $status]);
    }

    public function bulkMarkPending(): void
    {
        $n = $this->setSelectedStatus('pending');
        $this->bulkMessage = __(':n transaction(s) marked pending.', ['n' => $n]);
        $this->selected = [];
        unset($this->transactions, $this->totals);
    }

    public function bulkMarkCleared(): void
    {
        $n = $this->setSelectedStatus('cleared');
        $this->bulkMessage = __(':n transaction(s) marked cleared.', ['n' => $n]);
        $this->selected = [];
        unset($this->transactions, $this->totals);
    }

    public function openBulkEdit(): void
    {
        if ($this->selected === []) {
            return;
        }
        $this->bulkCategoryId = null;
        $this->bulkCounterpartyId = null;
        $this->bulkTagsToAdd = '';
        $this->showBulkEdit = true;
    }

    public function closeBulkEdit(): void
    {
        $this->showBulkEdit = false;
    }

    /**
     * Apply the modal's fields to the selected rows. Unset fields are
     * left alone — the modal is additive. Tags are appended (not
     * replaced) so bulk-tagging doesn't strip existing tags from a
     * row that already has some.
     */
    public function applyBulkEdit(): void
    {
        $ids = array_values(array_filter(array_map('intval', $this->selected)));
        if ($ids === []) {
            $this->closeBulkEdit();

            return;
        }

        $updates = [];
        if ($this->bulkCategoryId) {
            $updates['category_id'] = (int) $this->bulkCategoryId;
        }
        if ($this->bulkCounterpartyId) {
            $updates['counterparty_contact_id'] = (int) $this->bulkCounterpartyId;
        }
        if ($updates !== []) {
            Transaction::whereIn('id', $ids)->update($updates);
        }

        $tagNames = $this->parseTagList($this->bulkTagsToAdd);
        if ($tagNames !== []) {
            $tagIds = [];
            foreach ($tagNames as $name) {
                $tag = \App\Models\Tag::firstOrCreate(
                    ['slug' => \Illuminate\Support\Str::slug($name)],
                    ['name' => $name]
                );
                $tagIds[] = $tag->id;
            }
            foreach (Transaction::whereIn('id', $ids)->get() as $t) {
                $t->tags()->syncWithoutDetaching($tagIds);
            }
        }

        $parts = [];
        if (isset($updates['category_id'])) {
            $parts[] = __('category');
        }
        if (isset($updates['counterparty_contact_id'])) {
            $parts[] = __('counterparty');
        }
        if ($tagNames !== []) {
            $parts[] = __(':n tag(s)', ['n' => count($tagNames)]);
        }
        $this->bulkMessage = $parts === []
            ? __('Nothing to update.')
            : __('Updated :fields on :n transaction(s).', ['fields' => implode(' + ', $parts), 'n' => count($ids)]);

        $this->selected = [];
        $this->closeBulkEdit();
        unset($this->transactions, $this->totals);
    }

    /**
     * @return array<int, string>
     */
    private function parseTagList(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $name = trim(ltrim(trim($p), '#'));
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Category> */
    #[Computed]
    public function categoryOptions(): \Illuminate\Database\Eloquent\Collection
    {
        return Category::orderBy('name')->get(['id', 'name']);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Contact> */
    #[Computed]
    public function contactOptions(): \Illuminate\Database\Eloquent\Collection
    {
        return \App\Models\Contact::orderBy('display_name')->get(['id', 'display_name']);
    }

    #[Computed]
    public function accounts()
    {
        return Account::orderBy('name')->get();
    }

    #[Computed]
    public function currency(): string
    {
        if ($this->accountId !== '') {
            $acct = Account::find($this->accountId);
            if ($acct !== null && is_string($acct->currency) && $acct->currency !== '') {
                return $acct->currency;
            }
        }

        return CurrentHousehold::get()?->default_currency ?? 'USD';
    }

    #[Computed]
    public function transactions()
    {
        return Transaction::query()
            ->with([
                'account:id,name,currency',
                'category:id,name,slug',
                'counterparty:id,display_name',
                'tags:id,name,slug',
                'media' => fn ($q) => $q->where('mime', 'like', 'image/%'),
            ])
            ->when($this->accountId !== '', fn ($q) => $q->where('account_id', $this->accountId))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->tagFilter !== '', fn ($q) => $q
                ->whereHas('tags', fn ($t) => $t->where('slug', $this->tagFilter))
            )
            ->when($this->categoryFilter !== '', fn ($q) => $q->where('category_id', $this->categoryFilter))
            ->when($this->counterpartyFilter === 'none', fn ($q) => $q->whereNull('counterparty_contact_id'))
            ->when($this->counterpartyFilter !== '' && $this->counterpartyFilter !== 'none',
                fn ($q) => $q->where('counterparty_contact_id', $this->counterpartyFilter))
            ->whereDate('occurred_on', '>=', $this->from)
            ->whereDate('occurred_on', '<=', $this->to)
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('description', 'like', $term)
                    ->orWhereRaw('CAST(amount AS CHAR) LIKE ?', [$term])
                );
            })
            ->orderBy($this->sortBy, $this->sortDir)
            ->orderByDesc('id')
            ->paginate(50);
    }

    #[Computed]
    public function totals(): array
    {
        // One aggregate query instead of three (separate credits/debits
        // sums + count) — the dashboard + filters render pulls totals on
        // every state change, so the saved round-trips add up.
        $row = Transaction::query()
            ->when($this->accountId !== '', fn ($q) => $q->where('account_id', $this->accountId))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->whereDate('occurred_on', '>=', $this->from)
            ->whereDate('occurred_on', '<=', $this->to)
            ->selectRaw('COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) as credits')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) as debits')
            ->selectRaw('COUNT(*) as cnt')
            ->first();

        $credits = (float) ($row->credits ?? 0);
        $debits = (float) ($row->debits ?? 0);

        return [
            'credits' => $credits,
            'debits' => $debits,
            'net' => $credits + $debits,
            'count' => (int) ($row->cnt ?? 0),
        ];
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Transactions') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Filter by account, status, date, or free text.') }}</p>
        </div>
        <x-ui.new-record-button type="transaction" :label="__('New transaction')" shortcut="X" />
    </header>

    <dl class="flex gap-6 text-right">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('In') }}</dt>
                <dd class="mt-0.5 text-sm tabular-nums text-emerald-400">+{{ Formatting::money($this->totals['credits'], $this->currency) }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Out') }}</dt>
                <dd class="mt-0.5 text-sm tabular-nums text-rose-400">{{ Formatting::money($this->totals['debits'], $this->currency) }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Net') }}</dt>
                <dd class="mt-0.5 text-sm tabular-nums {{ $this->totals['net'] >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                    {{ ($this->totals['net'] >= 0 ? '+' : '').Formatting::money($this->totals['net'], $this->currency) }}
                </dd>
            </div>
    </dl>

    <form wire:submit.prevent
          class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4"
          aria-label="{{ __('Filters') }}">
        <div>
            <label for="q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="q" type="text" placeholder="{{ __('Description or amount…') }}"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="f-acct" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Account') }}</label>
            <select wire:model.live="accountId" id="f-acct"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All accounts') }}</option>
                @foreach ($this->accounts as $a)
                    <option value="{{ $a->id }}">{{ $a->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-status" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Status') }}</label>
            <select wire:model.live="status" id="f-status"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('Any') }}</option>
                @foreach (App\Support\Enums::transactionStatuses() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-from" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('From') }}</label>
            <input wire:model.live="from" id="f-from" type="date"
                   class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="f-to" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('To') }}</label>
            <input wire:model.live="to" id="f-to" type="date"
                   class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div class="w-56">
            <label for="f-cp" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Counterparty') }}</label>
            <div class="mt-1">
                <x-ui.searchable-select
                    id="f-cp"
                    model="counterpartyFilter"
                    :options="[
                        '' => __('Any — clear filter'),
                        'none' => __('— none (no counterparty) —'),
                    ] + $this->contactOptions->mapWithKeys(fn ($c) => [(string) $c->id => $c->display_name])->all()"
                    :placeholder="__('Any')" />
            </div>
        </div>
        <button type="button" wire:click="clearFilters"
                class="ml-auto rounded-md border border-neutral-800 px-3 py-1.5 text-xs text-neutral-400 hover:border-neutral-700 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            {{ __('Clear') }}
        </button>
    </form>

    @if ($tagFilter !== '')
        <div role="status" class="flex items-center justify-between rounded-lg border border-emerald-800/40 bg-emerald-900/20 px-4 py-2 text-sm text-emerald-200">
            <span class="font-mono">{{ __('Filtering by') }} #{{ $tagFilter }}</span>
            <button type="button" wire:click="$set('tagFilter', '')"
                    class="rounded-md px-2 py-1 text-xs text-emerald-200 hover:bg-emerald-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ __('Clear') }}
            </button>
        </div>
    @endif

    @if ($bulkMessage)
        <div role="status" class="rounded-md border border-emerald-800/40 bg-emerald-900/20 px-3 py-2 text-xs text-emerald-300">
            {{ $bulkMessage }}
        </div>
    @endif

    @if (! empty($selected))
        {{-- Sticky bulk-action bar. Stays in view while scrolling so
             actions remain reachable after the user picks rows from
             deep in the list. Appears the moment the first checkbox
             flips on. --}}
        <div role="region" aria-label="{{ __('Bulk transaction actions') }}"
             class="sticky top-0 z-20 flex flex-wrap items-center gap-2 rounded-xl border border-amber-800/50 bg-amber-950/40 px-4 py-2 text-xs text-amber-100 shadow-lg backdrop-blur">
            <span class="font-semibold tabular-nums">{{ trans_choice(':n selected|:n selected', count($selected), ['n' => count($selected)]) }}</span>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <button type="button" wire:click="selectAllVisible"
                        class="rounded-md border border-amber-800/60 bg-amber-900/30 px-3 py-1 text-amber-100 hover:bg-amber-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Select all visible') }}
                </button>
                <button type="button" wire:click="bulkMarkPending"
                        wire:confirm="{{ __('Mark :n transaction(s) as pending?', ['n' => count($selected)]) }}"
                        class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1 text-neutral-100 hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Mark pending') }}
                </button>
                <button type="button" wire:click="bulkMarkCleared"
                        wire:confirm="{{ __('Mark :n transaction(s) as cleared?', ['n' => count($selected)]) }}"
                        class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1 text-neutral-100 hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Mark cleared') }}
                </button>
                <button type="button" wire:click="openBulkEdit"
                        class="rounded-md border border-emerald-800/60 bg-emerald-900/30 px-3 py-1 text-emerald-100 hover:bg-emerald-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Bulk edit…') }}
                </button>
                <button type="button" wire:click="clearSelection"
                        class="rounded-md px-3 py-1 text-amber-200 hover:bg-amber-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Clear') }}
                </button>
            </div>
        </div>
    @endif

    @if ($this->transactions->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No transactions match those filters.') }}
        </div>
    @else
        @php
            $visibleIds = $this->transactions->pluck('id')->map(fn ($v) => (int) $v)->all();
            $visibleSel = array_values(array_intersect($selected, $visibleIds));
            $allVisibleSelected = count($visibleIds) > 0 && count($visibleSel) === count($visibleIds);
            $someVisibleSelected = count($visibleSel) > 0 && ! $allVisibleSelected;
        @endphp

        <div class="overflow-x-auto rounded-xl border border-neutral-800 bg-neutral-900/40">
            <table class="w-full min-w-[60rem] text-sm">
                <thead class="border-b border-neutral-800 text-left">
                    <tr>
                        <th scope="col" class="w-10 px-3 py-2">
                            {{-- wire:key forces Livewire to replace (not morph) the
                                 input whenever select-state changes — browsers leave
                                 the internal checked property stuck on the old value
                                 otherwise, so bulk actions that drain the selection
                                 leave the header box visually checked. --}}
                            <input type="checkbox"
                                   wire:key="txn-select-all-{{ count($selected) }}-{{ count($visibleIds) }}-{{ $allVisibleSelected ? 1 : 0 }}"
                                   wire:click="{{ $allVisibleSelected ? 'deselectAllVisible' : 'selectAllVisible' }}"
                                   @checked($allVisibleSelected)
                                   x-bind:indeterminate="{{ $someVisibleSelected ? 'true' : 'false' }}"
                                   aria-label="{{ $allVisibleSelected ? __('Deselect all visible') : __('Select all visible') }}"
                                   class="rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        </th>
                        <th scope="col" class="w-10 px-2 py-2"><span class="sr-only">{{ __('Scan') }}</span></th>
                        <x-ui.sortable-header column="occurred_on" :label="__('Date')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-ui.sortable-header column="description" :label="__('Description')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <th scope="col" class="px-3 py-2 text-left text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Counterparty') }}</th>
                        <th scope="col" class="px-3 py-2 text-left text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Category') }}</th>
                        <th scope="col" class="px-3 py-2 text-left text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Account') }}</th>
                        <x-ui.sortable-header column="amount" :label="__('Amount')" :sort-by="$sortBy" :sort-dir="$sortDir" align="right" />
                        <x-ui.sortable-header column="status" :label="__('Status')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800/60">
                    @foreach ($this->transactions as $t)
                        @php($scan = $t->media->firstWhere('pivot.role', 'receipt') ?? $t->media->first())
                        @php($isSelected = in_array($t->id, $selected, true))
                        {{-- Row-level wire:click opens the inspector. Inner controls
                             (select checkbox, scan thumbnail link) use wire:click.stop
                             so the row handler doesn't swallow their intent. --}}
                        <tr wire:key="txn-{{ $t->id }}"
                            tabindex="0" role="button"
                            wire:click="$dispatch('inspector-open', { type: 'transaction', id: {{ $t->id }} })"
                            @keydown.enter.prevent="$wire.dispatch('inspector-open', { type: 'transaction', id: {{ $t->id }} })"
                            class="cursor-pointer transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $isSelected ? 'bg-amber-950/20' : 'hover:bg-neutral-800/30' }}">
                            <td class="px-3 py-2 align-middle">
                                <input type="checkbox"
                                       wire:click.stop="toggleRow({{ $t->id }})"
                                       @checked($isSelected)
                                       aria-label="{{ __('Select transaction') }}"
                                       class="rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            </td>
                            <td class="px-2 py-2">
                                @if ($scan)
                                    <a href="{{ route('records.media', ['focus' => $scan->id]) }}"
                                       wire:click.stop
                                       title="{{ __('Open scan') }}"
                                       aria-label="{{ __('Open scan') }}"
                                       class="block h-8 w-8 overflow-hidden rounded border border-neutral-800 bg-neutral-950 hover:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                        <img src="{{ route('media.file', $scan) }}" alt="" loading="lazy"
                                             class="h-full w-full object-cover opacity-80 hover:opacity-100" />
                                    </a>
                                @else
                                    <span aria-hidden="true" class="block h-8 w-8 rounded border border-dashed border-neutral-800/60"></span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs text-neutral-400 tabular-nums">{{ Formatting::date($t->occurred_on) }}</td>
                            <td class="px-3 py-2 text-neutral-100">
                                {{ $t->description ?? '—' }}
                                @if ($t->reference_number ?? null)
                                    <span class="ml-2 text-[11px] text-neutral-500 tabular-nums">#{{ $t->reference_number }}</span>
                                @endif
                                <x-ui.tag-chips :tags="$t->tags" :active="$tagFilter" class="mt-1" />
                            </td>
                            <td class="px-3 py-2 text-xs text-neutral-400">{{ $t->counterparty?->display_name ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs text-neutral-400">{{ $t->category?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs text-neutral-400">{{ $t->account?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums {{ $t->amount >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                                {{ ($t->amount >= 0 ? '+' : '').Formatting::money((float) $t->amount, $t->account?->currency ?? $this->currency) }}
                            </td>
                            <td class="px-3 py-2 text-[11px] uppercase tracking-wider {{ $t->status === 'cleared' ? 'text-neutral-500' : 'text-amber-400' }}">
                                {{ $t->status }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div>{{ $this->transactions->links() }}</div>
    @endif

    @if ($showBulkEdit)
        <div x-cloak x-transition.opacity
             class="fixed inset-0 z-40 bg-black/60"
             wire:click="closeBulkEdit"
             aria-hidden="true"></div>

        <aside x-cloak
               x-data
               x-on:keydown.escape.window="$wire.closeBulkEdit()"
               role="dialog" aria-modal="true" aria-label="{{ __('Bulk edit transactions') }}"
               class="fixed left-1/2 top-24 z-50 w-full max-w-lg -translate-x-1/2 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-950 shadow-2xl">
            <header class="flex items-center justify-between border-b border-neutral-800 px-5 py-3">
                <h2 class="text-sm font-semibold text-neutral-100">{{ __('Bulk edit :n transaction(s)', ['n' => count($selected)]) }}</h2>
                <button type="button" wire:click="closeBulkEdit" aria-label="{{ __('Close') }}"
                        class="rounded-md p-1 text-neutral-500 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </button>
            </header>
            <div class="space-y-4 px-5 py-4">
                <p class="text-xs text-neutral-500">
                    {{ __('Only the fields you set here are applied — leave a field blank to keep existing values. Tags are appended, not replaced.') }}
                </p>

                <div>
                    <label for="btx-category" class="mb-1 block text-xs text-neutral-400">{{ __('Category') }}</label>
                    <x-ui.searchable-select
                        id="btx-category"
                        model="bulkCategoryId"
                        :options="['' => '— ' . __('unchanged') . ' —'] + $this->categoryOptions->mapWithKeys(fn ($c) => [$c->id => $c->name])->all()"
                        placeholder="—" />
                </div>

                <div>
                    <label for="btx-counterparty" class="mb-1 block text-xs text-neutral-400">{{ __('Counterparty') }}</label>
                    <x-ui.searchable-select
                        id="btx-counterparty"
                        model="bulkCounterpartyId"
                        :options="['' => '— ' . __('unchanged') . ' —'] + $this->contactOptions->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
                        placeholder="—" />
                    <p class="mt-1 text-[11px] text-neutral-500">{{ __('Create a new contact via the Inspector first if the one you need isn\'t in the list.') }}</p>
                </div>

                <div>
                    <label for="btx-tags" class="mb-1 block text-xs text-neutral-400">{{ __('Add tags') }}</label>
                    <input wire:model="bulkTagsToAdd" id="btx-tags" type="text"
                           placeholder="{{ __('#tax-2026 #groceries') }}"
                           class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 font-mono text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <p class="mt-1 text-[11px] text-neutral-500">{{ __('Space or comma separated. # optional. Existing tags stay.') }}</p>
                </div>
            </div>
            <footer class="flex items-center justify-end gap-2 border-t border-neutral-800 bg-neutral-900/50 px-5 py-3">
                <button type="button" wire:click="closeBulkEdit"
                        class="rounded-md px-3 py-1.5 text-xs text-neutral-400 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Cancel') }}
                </button>
                <button type="button" wire:click="applyBulkEdit"
                        class="rounded-md bg-emerald-600 px-4 py-1.5 text-xs font-medium text-emerald-50 hover:bg-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <span wire:loading.remove wire:target="applyBulkEdit">{{ __('Apply') }}</span>
                    <span wire:loading wire:target="applyBulkEdit">{{ __('Applying…') }}</span>
                </button>
            </footer>
        </aside>
    @endif
</div>

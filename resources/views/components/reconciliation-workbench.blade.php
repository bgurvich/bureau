<?php

use App\Models\Category;
use App\Models\Contact;
use App\Models\RecurringProjection;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Support\Formatting;
use App\Support\VendorReresolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reconciliation workbench.
 *
 * Primary surface: paginated list of *unreconciled* transactions — rows that
 * entered the ledger via an import path (statement / PayPal / OCR) and
 * haven't been confirmed by the user yet. Manual inserts via the inspector
 * arrive pre-reconciled since the user saw the row as they created it.
 *
 * Two non-Transaction surfaces sit below as a thin strip: overdue bills
 * (RecurringProjection) and half-done transfers (Transfer). They are
 * different entities with different actions, and the alerts-bell / radar
 * already surface them — we keep them here so the reconciliation page is
 * self-contained.
 */
new
#[Layout('components.layouts.app', ['title' => 'Reconciliation'])]
class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Column to sort the unreconciled list by. */
    #[Url(as: 'sort', except: 'occurred_on')]
    public string $sortBy = 'occurred_on';

    /** Sort direction: 'asc' or 'desc'. Date ascending is the default so
     *  the user walks the queue in the order the rows actually happened. */
    #[Url(as: 'dir', except: 'asc')]
    public string $sortDir = 'asc';

    /** @var array<int, int> Transaction ids the user has checked for bulk action. */
    public array $selected = [];

    public ?string $bulkMessage = null;

    /** Row id currently in inline-edit mode, or null when nothing is being edited. */
    public ?int $editingId = null;

    /**
     * Scratch state for the row in $editingId. Populated by editRow() from
     * the Transaction; saveRow() persists it back. Kept flat (one level) so
     * wire:model paths stay straightforward.
     *
     * @var array{description: string, counterparty_contact_id: int|null, category_id: int|null, match_pattern: string, amount: string}
     */
    public array $edit = [
        'description' => '',
        'counterparty_contact_id' => null,
        'category_id' => null,
        'match_pattern' => '',
        'amount' => '',
    ];

    public ?string $rowSaveMessage = null;

    /**
     * Toggle / set the sort column; clicking the active column flips the
     * direction, clicking a new column starts at ascending.
     */
    public function sort(string $column): void
    {
        $allowed = ['occurred_on', 'description', 'counterparty', 'category', 'account', 'amount'];
        if (! in_array($column, $allowed, true)) {
            return;
        }
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
        $this->resetPage();
    }

    #[On('inspector-saved')]
    #[On('vendor-resolve-finished')]
    public function refresh(): void
    {
        unset(
            $this->unreconciledTransactions,
            $this->unreconciledCount,
            $this->overdueProjections,
            $this->orphanedTransfers,
            $this->counts,
        );
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    // ── Unreconciled queue (primary surface) ──────────────────────────

    /** Paginated list of rows that imports left for the user to confirm. */
    #[Computed]
    public function unreconciledTransactions(): LengthAwarePaginator
    {
        $q = Transaction::query()
            ->with([
                'account:id,name,currency',
                'counterparty:id,display_name',
                'category:id,name,kind,color',
            ])
            ->whereNull('reconciled_at');

        // Sorting — direct column for local fields, left-join for relations
        // so name-based sorts don't trigger per-row lookups. Fall back to
        // occurred_on + id for deterministic ordering within ties.
        $dir = $this->sortDir === 'desc' ? 'desc' : 'asc';
        switch ($this->sortBy) {
            case 'description':
            case 'amount':
                $q->orderBy('transactions.'.$this->sortBy, $dir);
                break;
            case 'counterparty':
                $q->leftJoin('contacts', 'contacts.id', '=', 'transactions.counterparty_contact_id')
                    ->orderBy('contacts.display_name', $dir)
                    ->select('transactions.*');
                break;
            case 'category':
                $q->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
                    ->orderBy('categories.name', $dir)
                    ->select('transactions.*');
                break;
            case 'account':
                $q->leftJoin('accounts', 'accounts.id', '=', 'transactions.account_id')
                    ->orderBy('accounts.name', $dir)
                    ->select('transactions.*');
                break;
            case 'occurred_on':
            default:
                $q->orderBy('transactions.occurred_on', $dir);
                break;
        }
        $q->orderBy('transactions.id', $dir);

        if ($this->search !== '') {
            $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
            $q->where(fn ($w) => $w
                ->where('transactions.description', 'like', $needle)
                ->orWhere('transactions.memo', 'like', $needle)
            );
        }

        return $q->paginate(25);
    }

    #[Computed]
    public function unreconciledCount(): int
    {
        return Transaction::query()->whereNull('reconciled_at')->count();
    }

    public function confirmTransaction(int $id): void
    {
        Transaction::query()
            ->whereKey($id)
            ->whereNull('reconciled_at')
            ->update(['reconciled_at' => now()]);
        $this->refresh();
    }

    // ── Inline edit ───────────────────────────────────────────────────

    /** Pick list for the counterparty searchable-select. */
    #[Computed]
    public function contactOptions(): EloquentCollection
    {
        return Contact::query()->orderBy('display_name')->get(['id', 'display_name']);
    }

    /**
     * Pick list for the category searchable-select — id → name, sorted.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        return Category::query()
            ->with('parent:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'kind', 'parent_id'])
            ->mapWithKeys(fn (Category $c) => [$c->id => $c->displayLabel()])
            ->all();
    }

    /**
     * Create a new Contact from the inline "+ Add" option on the
     * counterparty searchable-select in edit mode. Writes the id into
     * edit.counterparty_contact_id and dispatches ss-option-added so
     * the widget labels the new row without a full re-render.
     */
    public function createCounterpartyForRow(string $name, string $modelKey): void
    {
        $name = trim($name);
        if ($name === '' || $modelKey !== 'edit.counterparty_contact_id') {
            return;
        }
        $contact = Contact::create([
            'kind' => 'org',
            'display_name' => $name,
            'is_vendor' => true,
        ]);
        $this->edit['counterparty_contact_id'] = (int) $contact->id;
        unset($this->contactOptions);
        $this->dispatch('ss-option-added', model: $modelKey, id: (int) $contact->id, label: $contact->display_name);
    }

    /**
     * Create a new Category (expense, slug from name, collision-suffixed)
     * from the inline "+ Add" option on the category searchable-select.
     */
    public function createCategoryForRow(string $name, string $modelKey): void
    {
        $name = trim($name);
        if ($name === '' || $modelKey !== 'edit.category_id') {
            return;
        }
        $slug = \Illuminate\Support\Str::slug($name);
        $base = $slug === '' ? 'cat-'.bin2hex(random_bytes(3)) : $slug;
        $suffix = 0;
        while (Category::where('slug', $suffix ? "{$base}-{$suffix}" : $base)->exists()) {
            $suffix++;
        }
        $category = Category::create([
            'name' => $name,
            'slug' => $suffix ? "{$base}-{$suffix}" : $base,
            'kind' => 'expense',
        ]);
        $this->edit['category_id'] = (int) $category->id;
        unset($this->categoryOptions);
        $this->dispatch('ss-option-added', model: $modelKey, id: (int) $category->id, label: $category->name);
    }

    public function editRow(int $id): void
    {
        $t = Transaction::query()->whereNull('reconciled_at')->find($id);
        if (! $t) {
            return;
        }
        $this->editingId = $id;
        $this->edit = [
            'description' => (string) ($t->description ?? ''),
            'counterparty_contact_id' => $t->counterparty_contact_id !== null ? (int) $t->counterparty_contact_id : null,
            'category_id' => $t->category_id !== null ? (int) $t->category_id : null,
            // match_pattern is surfaced here as an *edit hint*: empty means
            // "don't touch the contact's patterns". If the user types one,
            // saveRow appends it to the picked contact and re-resolves
            // sibling unreconciled rows.
            'match_pattern' => '',
            'amount' => (string) $t->amount,
        ];
        $this->rowSaveMessage = null;
    }

    public function cancelEditRow(): void
    {
        $this->editingId = null;
        $this->edit = [
            'description' => '',
            'counterparty_contact_id' => null,
            'category_id' => null,
            'match_pattern' => '',
            'amount' => '',
        ];
    }

    /**
     * Persist the inline edit back to the Transaction. If the user set a
     * new counterparty AND typed a non-empty match_pattern that isn't
     * already on that contact, append the pattern. Then re-resolve
     * unreconciled sibling rows whose description matches the newly-added
     * pattern so a single edit propagates through the queue.
     */
    public function saveRow(): void
    {
        if ($this->editingId === null) {
            return;
        }
        $id = (int) $this->editingId;
        $t = Transaction::query()->whereNull('reconciled_at')->find($id);
        if (! $t) {
            $this->cancelEditRow();

            return;
        }

        $validated = $this->validate([
            'edit.description' => ['nullable', 'string', 'max:500'],
            'edit.counterparty_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'edit.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'edit.match_pattern' => ['nullable', 'string', 'max:500'],
            'edit.amount' => ['required', 'numeric'],
        ]);

        $e = $validated['edit'];

        $t->forceFill([
            'description' => (string) ($e['description'] ?? ''),
            'counterparty_contact_id' => $e['counterparty_contact_id'] ?: null,
            'category_id' => $e['category_id'] ?: null,
            'amount' => $e['amount'],
        ])->save();

        // If the user typed a match_pattern AND picked a contact, teach
        // the contact that pattern (append, dedup-aware, skip noise) and
        // re-resolve every other unreconciled row that the pattern now
        // matches — the whole point of this inline edit flow.
        $pattern = trim((string) ($e['match_pattern'] ?? ''));
        $contactId = $e['counterparty_contact_id'] ?: null;
        $appended = null;
        if ($pattern !== '' && $contactId) {
            $appended = $this->appendPatternToContact((int) $contactId, $pattern);
        }

        $touched = 0;
        if ($appended !== null && $contactId) {
            $touched = $this->reresolveUnreconciledByPattern((int) $contactId, $appended, excludeId: $id);
        }

        // Category propagation — when the row is saved with both a contact
        // AND a category, apply that category to every *other* unreconciled
        // transaction tied to the same contact that currently has no
        // category. Scoped deliberately:
        //   - unreconciled only (reconciled history stays frozen; user can
        //     still use the contact-inspector backfill for those)
        //   - null category only (never overwrites an explicit choice)
        $categoryId = $e['category_id'] ?: null;
        $categoryApplied = 0;
        if ($contactId && $categoryId) {
            $categoryApplied = Transaction::query()
                ->whereNull('reconciled_at')
                ->whereKeyNot($id)
                ->where('counterparty_contact_id', (int) $contactId)
                ->whereNull('category_id')
                ->update(['category_id' => (int) $categoryId]);
        }

        $parts = [__('Saved')];
        if ($touched > 0) {
            $parts[] = __('vendor applied to :n other row(s)', ['n' => $touched]);
        }
        if ($categoryApplied > 0) {
            $parts[] = __('category applied to :n other row(s)', ['n' => $categoryApplied]);
        }
        $this->rowSaveMessage = implode(' · ', $parts).'.';

        $this->cancelEditRow();
        $this->refresh();
    }

    /**
     * Append $pattern to the contact's match_patterns if it isn't already
     * present (case-insensitive) and isn't the contact's display-name
     * fingerprint (which patternList() already self-heals in).
     *
     * Returns the pattern string if it was actually appended, null if
     * it was a duplicate / noise and nothing changed.
     */
    private function appendPatternToContact(int $contactId, string $pattern): ?string
    {
        $contact = Contact::find($contactId);
        if ($contact === null) {
            return null;
        }
        $existing = VendorReresolver::parsePatterns((string) ($contact->match_patterns ?? ''));
        $existingLower = array_map(fn ($p) => mb_strtolower($p), $existing);
        $displayFp = VendorReresolver::fingerprint((string) $contact->display_name);
        $pl = mb_strtolower($pattern);
        if (in_array($pl, $existingLower, true) || $pl === $displayFp) {
            return null;
        }
        $combined = implode("\n", array_merge($existing, [$pattern]));
        $contact->forceFill(['match_patterns' => $combined])->save();

        return $pattern;
    }

    /**
     * Scan unreconciled rows (other than $excludeId). For each whose
     * description matches $pattern and whose counterparty is either null
     * or currently points elsewhere by auto-detect, set it to $contactId.
     *
     * Returns the number of rows updated. Keeps manual assignments where
     * the existing contact's display_name appears as a substring of the
     * description — the same manual-vs-auto heuristic VendorReresolver::run
     * uses, so user picks aren't stomped by sibling re-resolve.
     *
     * Performance shape: one query to pull candidates + existing
     * counterparty names (no per-row Contact::find), one batched UPDATE
     * at the end (no per-row UPDATE). With 100+ unreconciled rows the
     * old loop issued ~2N round-trips; this version issues 3 regardless
     * of N (candidate fetch, contact-name fetch, single bulk update).
     */
    private function reresolveUnreconciledByPattern(int $contactId, string $pattern, int $excludeId): int
    {
        $regex = '#'.str_replace('#', '\#', $pattern).'#iu';

        $candidates = Transaction::query()
            ->whereNull('reconciled_at')
            ->whereKeyNot($excludeId)
            ->whereNotNull('description')
            ->where(function ($q) use ($contactId) {
                // Skip rows already pointing at the target contact.
                $q->whereNull('counterparty_contact_id')
                    ->orWhere('counterparty_contact_id', '!=', $contactId);
            })
            ->get(['id', 'description', 'counterparty_contact_id']);

        if ($candidates->isEmpty()) {
            return 0;
        }

        // Bulk-load the display_name of every currently-assigned contact
        // referenced by the candidates — single query instead of N finds.
        $currentContactIds = $candidates->pluck('counterparty_contact_id')
            ->filter()
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values();
        $nameById = $currentContactIds->isEmpty()
            ? collect()
            : Contact::query()
                ->whereIn('id', $currentContactIds)
                ->pluck('display_name', 'id')
                ->map(fn ($v) => mb_strtolower((string) $v));

        $idsToUpdate = [];
        foreach ($candidates as $t) {
            $descLower = mb_strtolower((string) $t->description);
            if (@preg_match($regex, $descLower) !== 1) {
                continue;
            }
            $currentId = $t->counterparty_contact_id !== null ? (int) $t->counterparty_contact_id : null;
            if ($currentId !== null) {
                // Manual-pick guard: if the existing contact's name
                // appears in the description, treat the row as
                // user-assigned and leave it alone.
                $currentName = (string) ($nameById[$currentId] ?? '');
                if ($currentName !== '' && str_contains($descLower, $currentName)) {
                    continue;
                }
            }
            $idsToUpdate[] = (int) $t->id;
        }

        if ($idsToUpdate === []) {
            return 0;
        }

        Transaction::query()
            ->whereIn('id', $idsToUpdate)
            ->update(['counterparty_contact_id' => $contactId]);

        return count($idsToUpdate);
    }

    public function deleteTransaction(int $transactionId): void
    {
        Transaction::where('id', $transactionId)->delete();
        $this->refresh();
    }

    // ── Bulk-select ───────────────────────────────────────────────────

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
        $ids = $this->unreconciledTransactions->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->selected = array_values(array_unique(array_merge($this->selected, $ids)));
    }

    public function deselectAllVisible(): void
    {
        $visible = $this->unreconciledTransactions->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->selected = array_values(array_diff($this->selected, $visible));
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function bulkConfirm(): void
    {
        if ($this->selected === []) {
            return;
        }
        $ids = array_values(array_filter(array_map('intval', $this->selected)));
        $n = Transaction::query()
            ->whereIn('id', $ids)
            ->whereNull('reconciled_at')
            ->update(['reconciled_at' => now()]);
        $this->bulkMessage = __(':n transaction(s) confirmed.', ['n' => $n]);
        $this->selected = [];
        $this->refresh();
    }

    /**
     * One-click confirm every row on the currently-visible page. Avoids
     * the "check all → move mouse across the row to the bulk button"
     * detour; the typical reconciliation session walks page-by-page,
     * confirming everything that looks fine and opening the inspector
     * only for the exceptions.
     */
    public function confirmPage(): void
    {
        $ids = $this->unreconciledTransactions->pluck('id')->map(fn ($v) => (int) $v)->all();
        if ($ids === []) {
            return;
        }
        $n = Transaction::query()
            ->whereIn('id', $ids)
            ->whereNull('reconciled_at')
            ->update(['reconciled_at' => now()]);
        $this->bulkMessage = __(':n transaction(s) confirmed.', ['n' => $n]);
        $this->refresh();
    }

    // ── Non-transaction surfaces (bills · transfers) ──────────────────

    #[Computed]
    public function overdueProjections(): EloquentCollection
    {
        return RecurringProjection::query()
            ->with('rule:id,title,account_id,counterparty_contact_id')
            ->whereIn('status', ['projected', 'overdue'])
            ->whereNull('matched_transaction_id')
            ->whereNull('matched_transfer_id')
            ->where('autopay', false)
            ->whereDate('due_on', '<', now()->toDateString())
            ->orderBy('due_on')
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function orphanedTransfers(): EloquentCollection
    {
        return Transfer::query()
            ->with(['fromAccount:id,name', 'toAccount:id,name'])
            ->where('status', 'pending')
            ->whereDate('occurred_on', '<', CarbonImmutable::now()->subDays(7)->toDateString())
            ->orderByDesc('occurred_on')
            ->limit(50)
            ->get();
    }

    /**
     * @return array{unreconciled:int, projections:int, transfers:int, total:int}
     */
    #[Computed]
    public function counts(): array
    {
        $c = [
            'unreconciled' => $this->unreconciledCount,
            'projections' => $this->overdueProjections->count(),
            'transfers' => $this->orphanedTransfers->count(),
        ];
        $c['total'] = array_sum($c);

        return $c;
    }

};
?>

<div class="space-y-5">
    <header>
        <h2 class="text-base font-semibold text-neutral-100">{{ __('Reconciliation workbench') }}</h2>
        <p class="mt-1 text-xs text-neutral-500">
            {{ __('Review imported rows and confirm them one at a time — or bulk-confirm once you have eyeballed a batch.') }}
        </p>
    </header>

    {{-- Inline tools — same muscle memory: pop open, tweak, close. --}}
    <div class="grid gap-3 md:grid-cols-2">
        {{-- Vendor-ignore editor: add filler patterns without bouncing to
             /settings; Re-resolve refreshes vendor links on existing rows. --}}
        <details class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-4 text-xs">
            <summary class="cursor-pointer text-neutral-300">
                {{ __('Vendor auto-detect · ignore list') }}
                <span class="ml-1 text-neutral-600">{{ __('(add filler patterns)') }}</span>
            </summary>
            <div class="mt-3">
                <livewire:vendor-ignore-editor />
            </div>
        </details>

        {{-- Recurring-bill discovery: scan the confirmed ledger for
             repeating outflows and promote them to RecurringRule. Lives
             here because reconciliation is the natural moment to notice
             "hey, this looks like a monthly subscription". --}}
        <details class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-4 text-xs">
            <summary class="cursor-pointer text-neutral-300">
                {{ __('Recurring-bill discovery') }}
                <span class="ml-1 text-neutral-600">{{ __('(promote repeating rows to bills)') }}</span>
            </summary>
            <div class="mt-3">
                <livewire:recurring-discoveries />
            </div>
        </details>
    </div>

    @php($counts = $this->counts)

    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border {{ $counts['unreconciled'] ? 'border-amber-700/50 bg-amber-950/30' : 'border-neutral-800 bg-neutral-900/40' }} px-4 py-3 sm:col-span-2">
            <dt class="text-[10px] uppercase tracking-wider {{ $counts['unreconciled'] ? 'text-amber-300' : 'text-neutral-500' }}">{{ __('Unreconciled imports') }}</dt>
            <dd class="mt-1 text-2xl font-semibold tabular-nums {{ $counts['unreconciled'] ? 'text-amber-100' : 'text-neutral-400' }}">{{ $counts['unreconciled'] }}</dd>
        </div>
        <div class="rounded-xl border border-neutral-800 bg-neutral-900/40 px-4 py-3">
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Unmatched bills') }}</dt>
            <dd class="mt-1 text-xl font-semibold tabular-nums {{ $counts['projections'] ? 'text-rose-300' : 'text-neutral-400' }}">{{ $counts['projections'] }}</dd>
        </div>
        <div class="rounded-xl border border-neutral-800 bg-neutral-900/40 px-4 py-3">
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Half-done transfers') }}</dt>
            <dd class="mt-1 text-xl font-semibold tabular-nums {{ $counts['transfers'] ? 'text-amber-300' : 'text-neutral-400' }}">{{ $counts['transfers'] }}</dd>
        </div>
    </dl>

    @if ($counts['total'] === 0)
        <div class="rounded-xl border border-emerald-800/50 bg-emerald-950/30 px-6 py-10 text-center text-sm text-emerald-200">
            {{ __('Nothing to reconcile. Everything is clean.') }}
        </div>
    @endif

    {{-- ── Primary: unreconciled queue ──────────────────────────────── --}}
    <section aria-labelledby="recon-unrec" class="space-y-3">
        <h3 id="recon-unrec" class="flex items-baseline justify-between text-[11px] font-medium uppercase tracking-wider text-neutral-400">
            <span>{{ __('Unreconciled — newly imported, awaiting confirmation') }}</span>
            @if ($counts['unreconciled'])
                <span class="text-amber-400 tabular-nums">{{ $counts['unreconciled'] }}</span>
            @endif
        </h3>

        @if ($bulkMessage)
            <div role="status" class="rounded-xl border border-emerald-800/40 bg-emerald-950/30 px-3 py-2 text-xs text-emerald-200">
                {{ $bulkMessage }}
            </div>
        @endif
        @if ($rowSaveMessage)
            <div role="status" class="rounded-xl border border-emerald-800/40 bg-emerald-950/30 px-3 py-2 text-xs text-emerald-200">
                {{ $rowSaveMessage }}
            </div>
        @endif

        @if (count($selected) > 0)
            {{-- Sticky bulk-action bar (same muscle memory as transactions-index). --}}
            <div role="region" aria-label="{{ __('Bulk actions') }}"
                 class="sticky top-0 z-20 flex flex-wrap items-center gap-2 rounded-xl border border-amber-800/50 bg-amber-950/40 px-4 py-2 text-xs text-amber-100 shadow-lg backdrop-blur">
                <span class="font-medium">{{ __(':n selected', ['n' => count($selected)]) }}</span>
                @php($allVisibleIds = $this->unreconciledTransactions->pluck('id')->map(fn ($v) => (int) $v)->all())
                @php($allVisibleSelected = $allVisibleIds && count(array_intersect($selected, $allVisibleIds)) === count($allVisibleIds))
                <button type="button" wire:click="{{ $allVisibleSelected ? 'deselectAllVisible' : 'selectAllVisible' }}"
                        class="rounded-md border border-amber-700 bg-amber-900/40 px-2 py-0.5 text-[10px] uppercase tracking-wider text-amber-100 hover:bg-amber-900/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ $allVisibleSelected ? __('Deselect page') : __('Select page') }}
                </button>
                <span class="flex-1"></span>
                <button type="button" wire:click="bulkConfirm"
                        wire:confirm="{{ __('Confirm :n selected transaction(s)?', ['n' => count($selected)]) }}"
                        class="rounded-md border border-emerald-700/60 bg-emerald-900/40 px-2 py-0.5 text-[10px] uppercase tracking-wider text-emerald-100 hover:bg-emerald-900/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Confirm selected') }}
                </button>
                <button type="button" wire:click="clearSelection"
                        class="rounded-md border border-neutral-700 bg-neutral-900 px-2 py-0.5 text-[10px] uppercase tracking-wider text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Clear') }}
                </button>
            </div>
        @endif

        @if ($counts['unreconciled'] === 0)
            <div class="rounded-xl border border-neutral-800 bg-neutral-900/40 px-6 py-8 text-center text-xs text-neutral-500">
                {{ __('No unreconciled imports. New rows from statement / PayPal / OCR imports will land here.') }}
            </div>
        @else
            <div class="flex items-center gap-2 text-xs">
                <label for="recon-search" class="sr-only">{{ __('Search') }}</label>
                <input id="recon-search" type="search" wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('Search description or memo') }}"
                       class="w-full max-w-xs rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1 text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <span class="flex-1"></span>
                @php($pageCount = $this->unreconciledTransactions->count())
                {{-- One-click confirm the whole visible page. Deliberately
                     lives next to the search input (not in the bulk bar)
                     so the click is reachable without a checkbox round-trip. --}}
                <button type="button" wire:click="confirmPage"
                        wire:confirm="{{ __('Confirm all :n row(s) on this page?', ['n' => $pageCount]) }}"
                        class="rounded-md border border-emerald-700/60 bg-emerald-900/30 px-3 py-1 text-[11px] font-medium uppercase tracking-wider text-emerald-200 hover:bg-emerald-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Confirm page (:n)', ['n' => $pageCount]) }}
                </button>
            </div>

            {{-- overflow-visible (not hidden) so the bottom-row vendor /
                 category dropdowns can escape the container. Rounded
                 corners on the outer div stay correct visually; the
                 table sits inset enough that clipping isn't needed. --}}
            <div class="overflow-visible rounded-xl border border-neutral-800 bg-neutral-900/40">
                <table class="w-full text-xs">
                    <thead class="bg-neutral-900/60 text-[10px] uppercase tracking-wider text-neutral-500">
                        <tr>
                            @php($pageIds = $this->unreconciledTransactions->pluck('id')->map(fn ($v) => (int) $v)->all())
                            @php($allVisibleSelected = $pageIds && count(array_intersect($selected, $pageIds)) === count($pageIds))
                            <th scope="col" class="w-8 px-2 py-2 text-left">
                                {{-- wire:key forces Livewire to replace the input
                                     (rather than morph its attributes) whenever
                                     the visible/selected shape changes. Fixes the
                                     "checkbox stays checked after bulk confirm"
                                     case where morphing updated the checked
                                     attribute but the browser's internal checked
                                     property stayed stuck on the old value. --}}
                                <input type="checkbox"
                                       wire:key="recon-select-all-{{ count($selected) }}-{{ count($pageIds) }}-{{ $allVisibleSelected ? 1 : 0 }}"
                                       aria-label="{{ __('Select all visible') }}"
                                       @checked($allVisibleSelected)
                                       wire:click="{{ $allVisibleSelected ? 'deselectAllVisible' : 'selectAllVisible' }}"
                                       class="h-3.5 w-3.5 rounded border-neutral-600 bg-neutral-900 text-amber-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            </th>
                            <x-ui.sortable-header column="occurred_on" :label="__('Date')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                            <x-ui.sortable-header column="description" :label="__('Description')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                            <x-ui.sortable-header column="counterparty" :label="__('Vendor')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                            <x-ui.sortable-header column="category" :label="__('Category')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                            <x-ui.sortable-header column="account" :label="__('Account')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                            <x-ui.sortable-header column="amount" :label="__('Amount')" :sort-by="$sortBy" :sort-dir="$sortDir" align="right" />
                            <th scope="col" class="px-2 py-2 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-800">
                        @foreach ($this->unreconciledTransactions as $t)
                            @php($checked = in_array((int) $t->id, $selected, true))
                            @php($isEditing = $editingId === (int) $t->id)
                            {{-- Row-click enters edit mode when idle. In edit
                                 mode we drop the row-level click handler so
                                 typing in the nested inputs doesn't re-trigger
                                 edit. Checkbox + action buttons use
                                 wire:click.stop to not bubble. --}}
                            <tr wire:key="rec-{{ $t->id }}"
                                @class([
                                    'transition' => true,
                                    'cursor-pointer hover:bg-neutral-900/60' => ! $isEditing,
                                    'bg-neutral-900/60' => $isEditing,
                                ])
                                @if (! $isEditing) wire:click="editRow({{ $t->id }})" @endif>
                                <td class="w-8 px-2 py-1.5">
                                    <input type="checkbox"
                                           aria-label="{{ __('Select row') }}"
                                           @checked($checked)
                                           wire:click.stop="toggleRow({{ $t->id }})"
                                           class="h-3.5 w-3.5 rounded border-neutral-600 bg-neutral-900 text-amber-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                </td>
                                <td class="px-2 py-1.5 whitespace-nowrap text-neutral-400 tabular-nums">{{ Formatting::date($t->occurred_on) }}</td>
                                @if ($isEditing)
                                    {{-- Inline edit strip. Enter on any plain input saves
                                         and re-resolves sibling unreconciled rows; Esc cancels.
                                         The searchable-select eats its own Enter for option
                                         picking, so only plain inputs bind the shortcut. --}}
                                    <td class="px-2 py-1">
                                        <input wire:model="edit.description"
                                               wire:keydown.enter.prevent="saveRow"
                                               wire:keydown.escape.prevent="cancelEditRow"
                                               type="text" autofocus
                                               aria-label="{{ __('Description') }}"
                                               class="w-full rounded border border-neutral-700 bg-neutral-950 px-2 py-1 text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    </td>
                                    <td class="px-2 py-1 text-[11px]">
                                        <x-ui.searchable-select
                                            id="rec-cp-{{ $t->id }}"
                                            model="edit.counterparty_contact_id"
                                            :options="['' => '— ' . __('none') . ' —'] + $this->contactOptions->mapWithKeys(fn ($c) => [(string) $c->id => $c->display_name])->all()"
                                            :placeholder="__('— none —')"
                                            allow-create
                                            create-method="createCounterpartyForRow"
                                            edit-inspector-type="contact" />
                                    </td>
                                    <td class="px-2 py-1 text-[11px]">
                                        <x-ui.searchable-select
                                            id="rec-cat-{{ $t->id }}"
                                            model="edit.category_id"
                                            :options="['' => '— ' . __('none') . ' —'] + array_map(fn ($v) => (string) $v, $this->categoryOptions)"
                                            :placeholder="__('— none —')"
                                            allow-create
                                            create-method="createCategoryForRow"
                                            edit-inspector-type="category" />
                                    </td>
                                    <td class="px-2 py-1.5 text-neutral-500">{{ $t->account?->name }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums">
                                        <input wire:model="edit.amount"
                                               wire:keydown.enter.prevent="saveRow"
                                               wire:keydown.escape.prevent="cancelEditRow"
                                               type="number" step="0.01"
                                               aria-label="{{ __('Amount') }}"
                                               class="w-full rounded border border-neutral-700 bg-neutral-950 px-2 py-1 text-right text-xs tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    </td>
                                    <td class="px-2 py-1 text-right">
                                        <div class="flex flex-col items-stretch gap-1">
                                            <input wire:model="edit.match_pattern"
                                                   wire:keydown.enter.prevent="saveRow"
                                                   wire:keydown.escape.prevent="cancelEditRow"
                                                   type="text"
                                                   placeholder="{{ __('match pattern (optional)') }}"
                                                   aria-label="{{ __('Match pattern') }}"
                                                   class="w-full rounded border border-neutral-700 bg-neutral-950 px-2 py-1 font-mono text-[11px] text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                            <div class="inline-flex items-center justify-end gap-1">
                                                <button type="button" wire:click.stop="saveRow"
                                                        class="rounded bg-emerald-600 px-2 py-0.5 text-[10px] font-medium text-emerald-50 hover:bg-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                                    {{ __('Update') }}
                                                </button>
                                                <button type="button" wire:click.stop="cancelEditRow"
                                                        class="rounded border border-neutral-700 px-2 py-0.5 text-[10px] text-neutral-400 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                                    {{ __('Cancel') }}
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                @else
                                    <td class="px-2 py-1.5 text-neutral-100">
                                        <div class="whitespace-pre-wrap break-words">{{ $t->description ?: __('(no description)') }}</div>
                                        @if ($t->memo)
                                            <div class="whitespace-pre-wrap break-words text-[11px] text-neutral-500">{{ $t->memo }}</div>
                                        @endif
                                    </td>
                                    <td class="px-2 py-1.5 text-neutral-300">
                                        @if ($t->counterparty)
                                            {{ $t->counterparty->display_name }}
                                        @else
                                            <span class="text-neutral-600">{{ __('—') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-1.5 text-neutral-300">
                                        @if ($t->category)
                                            <span class="inline-flex items-center gap-1">
                                                @if ($t->category->color)
                                                    <span class="inline-block h-2 w-2 rounded-sm" style="background-color: {{ $t->category->color }}"></span>
                                                @endif
                                                <span>{{ $t->category->name }}</span>
                                            </span>
                                        @else
                                            <span class="text-neutral-600">{{ __('—') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-1.5 text-neutral-500">{{ $t->account?->name }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums {{ (float) $t->amount < 0 ? 'text-neutral-100' : 'text-emerald-400' }}">
                                        {{ Formatting::money((float) $t->amount, $t->currency ?? ($t->account?->currency ?? 'USD')) }}
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        <div class="inline-flex items-center gap-1">
                                            <button type="button" wire:click.stop="confirmTransaction({{ $t->id }})"
                                                    class="rounded-md border border-emerald-700/50 bg-emerald-900/30 px-2 py-0.5 text-[10px] uppercase tracking-wider text-emerald-200 hover:bg-emerald-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                                {{ __('Confirm') }}
                                            </button>
                                            <button type="button" wire:click.stop="$dispatch('inspector-open', { type: 'transaction', id: {{ $t->id }} })"
                                                    class="rounded-md border border-neutral-700 bg-neutral-900 px-2 py-0.5 text-[10px] uppercase tracking-wider text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                                {{ __('Open') }}
                                            </button>
                                            <button type="button" wire:click.stop="deleteTransaction({{ $t->id }})"
                                                    wire:confirm="{{ __('Delete this transaction?') }}"
                                                    class="rounded-md border border-rose-800/40 bg-rose-950/20 px-2 py-0.5 text-[10px] uppercase tracking-wider text-rose-300 hover:bg-rose-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                                {{ __('Delete') }}
                                            </button>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div>
                {{ $this->unreconciledTransactions->onEachSide(1)->links() }}
            </div>
        @endif
    </section>

    {{-- ── Non-transaction open items (bills · half-done transfers) ──
         These entities aren't Transactions so they can't join the queue
         above, but they belong on this page because they're the remaining
         "needs a decision" surfaces. --}}
    @if ($this->overdueProjections->isNotEmpty() || $this->orphanedTransfers->isNotEmpty())
        <section aria-labelledby="recon-other" class="space-y-3">
            <h3 id="recon-other" class="text-[11px] font-medium uppercase tracking-wider text-neutral-400">
                {{ __('Other open items') }}
            </h3>

            @if ($this->overdueProjections->isNotEmpty())
                <section aria-labelledby="recon-projections" class="space-y-2">
                    <h4 id="recon-projections" class="flex items-baseline justify-between text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                        <span>{{ __('Unmatched overdue bills') }}</span>
                        <span class="text-rose-400">{{ $this->overdueProjections->count() }}</span>
                    </h4>
                    <ul class="divide-y divide-neutral-800 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
                        @foreach ($this->overdueProjections as $p)
                            <li class="flex items-center justify-between gap-4 px-4 py-2 text-sm">
                                <div class="min-w-0">
                                    <div class="truncate text-neutral-100">{{ $p->rule?->title ?? __('Bill') }}</div>
                                    <div class="text-[11px] text-neutral-500 tabular-nums">{{ __('due :d', ['d' => Formatting::date($p->due_on)]) }}</div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="shrink-0 tabular-nums text-neutral-400">{{ Formatting::money((float) $p->amount, $p->currency ?? 'USD') }}</span>
                                    <button type="button"
                                            wire:click="$dispatch('inspector-mark-paid', { projectionId: {{ $p->id }} })"
                                            class="rounded-md border border-emerald-700/40 bg-emerald-900/20 px-2 py-0.5 text-[10px] uppercase tracking-wider text-emerald-300 hover:bg-emerald-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                        {{ __('Mark paid') }}
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($this->orphanedTransfers->isNotEmpty())
                <section aria-labelledby="recon-transfers" class="space-y-2">
                    <h4 id="recon-transfers" class="flex items-baseline justify-between text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                        <span>{{ __('Half-done transfers') }}</span>
                        <span class="text-amber-400">{{ $this->orphanedTransfers->count() }}</span>
                    </h4>
                    <ul class="divide-y divide-neutral-800 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
                        @foreach ($this->orphanedTransfers as $xfer)
                            <li class="flex items-center justify-between gap-4 px-4 py-2 text-sm">
                                <div class="min-w-0">
                                    <div class="truncate text-neutral-100">
                                        {{ $xfer->fromAccount?->name }} → {{ $xfer->toAccount?->name }}
                                    </div>
                                    <div class="text-[11px] text-neutral-500 tabular-nums">
                                        {{ Formatting::date($xfer->occurred_on) }} · {{ __('pending for >7 days') }}
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="shrink-0 tabular-nums text-neutral-300">
                                        {{ Formatting::money((float) $xfer->from_amount, $xfer->from_currency ?? 'USD') }}
                                    </span>
                                    <button type="button"
                                            wire:click="$dispatch('inspector-open', { type: 'transfer', id: {{ $xfer->id }} })"
                                            class="rounded-md border border-neutral-700 bg-neutral-900 px-2 py-0.5 text-[10px] uppercase tracking-wider text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                        {{ __('Edit') }}
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </section>
    @endif
</div>

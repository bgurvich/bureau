<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Media;
use App\Models\Transaction;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use App\Support\ProjectionMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Inbox'])]
class extends Component
{
    /** @var array<int, int> media ids selected for bulk action */
    public array $selected = [];

    #[Url(as: 'src')]
    public string $sourceFilter = '';

    public bool $showBulkTxnForm = false;

    public ?int $bulk_account_id = null;

    public string $bulk_status = 'cleared';

    public ?string $bulkMessage = null;

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->items, $this->counts);
        $this->selected = [];
    }

    public function toggle(int $id): void
    {
        if (in_array($id, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$id]));
        } else {
            $this->selected[] = $id;
        }
    }

    public function selectAll(): void
    {
        $this->selected = $this->items->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->showBulkTxnForm = false;
    }

    public function dismissSelected(): void
    {
        if ($this->selected !== []) {
            Media::whereIn('id', $this->selected)->update(['processed_at' => now()]);
            foreach ($this->selected as $id) {
                \App\Models\MailMessage::cascadeProcessedFromMedia((int) $id);
            }
        }
        $this->bulkMessage = __(':n dismissed.', ['n' => count($this->selected)]);
        $this->refresh();
    }

    public function dismissOne(int $id): void
    {
        Media::whereKey($id)->update(['processed_at' => now()]);
        \App\Models\MailMessage::cascadeProcessedFromMedia($id);
        $this->refresh();
    }

    public function openBulkTxnForm(): void
    {
        $this->showBulkTxnForm = true;
        $this->bulkMessage = null;
    }

    public function cancelBulkTxnForm(): void
    {
        $this->showBulkTxnForm = false;
        $this->bulk_account_id = null;
    }

    /**
     * Create one Transaction per selected scan using that scan's extracted
     * fields + the shared account the user picked. Scans missing an amount
     * are skipped. Each created transaction gets the scan attached and the
     * scan marked processed; ProjectionMatcher links the transaction to a
     * matching recurring-bill projection when one exists.
     */
    public function bulkCreateTransactions(): void
    {
        $this->validate([
            'bulk_account_id' => 'required|integer|exists:accounts,id',
            'bulk_status' => 'required|string',
        ]);

        if ($this->selected === []) {
            $this->bulkMessage = __('No scans selected.');

            return;
        }

        $created = 0;
        $skipped = 0;

        $scans = Media::whereIn('id', $this->selected)
            ->whereNull('processed_at')
            ->get();

        foreach ($scans as $scan) {
            $extracted = is_array($scan->ocr_extracted) ? $scan->ocr_extracted : [];
            $amount = $extracted['amount'] ?? null;
            if (! is_numeric($amount)) {
                $skipped++;

                continue;
            }

            $txn = Transaction::create([
                'account_id' => $this->bulk_account_id,
                'occurred_on' => $extracted['issued_on'] ?? now()->toDateString(),
                'amount' => -1 * abs((float) $amount),
                'currency' => $this->currency($extracted),
                'description' => $extracted['vendor'] ?? ($scan->original_name ?? null),
                'category_id' => $this->resolveCategoryId($extracted['category_suggestion'] ?? null),
                'tax_amount' => is_numeric($extracted['tax_amount'] ?? null) ? (float) $extracted['tax_amount'] : null,
                'status' => $this->bulk_status,
            ]);

            if (! $txn->media()->where('media.id', $scan->id)->exists()) {
                $txn->media()->attach($scan->id, ['role' => 'receipt']);
            }
            $scan->forceFill(['processed_at' => now()])->save();
            \App\Models\MailMessage::cascadeProcessedFromMedia($scan->id);

            ProjectionMatcher::attempt($txn);
            $created++;
        }

        $this->bulkMessage = __(':c created, :s skipped (no amount).', ['c' => $created, 's' => $skipped]);
        $this->showBulkTxnForm = false;
        $this->bulk_account_id = null;
        $this->refresh();
    }

    public function bulkDelete(): void
    {
        if ($this->selected === []) {
            return;
        }
        foreach (Media::whereIn('id', $this->selected)->get() as $m) {
            try {
                Storage::disk($m->disk ?: 'local')->delete($m->path);
            } catch (\Throwable) {
                // Best-effort file cleanup.
            }
            $m->delete();
        }
        $this->bulkMessage = __(':n deleted.', ['n' => count($this->selected)]);
        $this->refresh();
    }

    /**
     * @param  array<string, mixed>  $extracted
     */
    private function currency(array $extracted): string
    {
        $c = is_string($extracted['currency'] ?? null) ? strtoupper((string) $extracted['currency']) : '';
        if (preg_match('/^[A-Z]{3}$/', $c)) {
            return $c;
        }

        return \App\Support\CurrentHousehold::get()?->default_currency ?? 'USD';
    }

    private function resolveCategoryId(mixed $suggestion): ?int
    {
        if (! is_string($suggestion)) {
            return null;
        }
        $needle = mb_strtolower(trim($suggestion));
        if ($needle === '') {
            return null;
        }

        return Category::query()
            ->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(slug) = ?', [$needle])
                    ->orWhereRaw('LOWER(name) = ?', [$needle])
                    ->orWhereRaw('LOWER(slug) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%'.$needle.'%']);
            })
            ->value('id');
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::orderBy('name')->get(['id', 'name', 'currency']);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return [
            'unprocessed' => Media::whereNull('processed_at')
                ->where('ocr_status', 'done')
                ->whereNotNull('ocr_extracted')
                ->count(),
        ];
    }

    /**
     * Unprocessed media with extracted data — the bill-intake queue. Chronological, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function items(): Collection
    {
        return Media::query()
            ->whereNull('processed_at')
            ->where('ocr_status', 'done')
            ->whereNotNull('ocr_extracted')
            ->when($this->sourceFilter !== '', fn ($q) => $q->where('source', $this->sourceFilter))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function (Media $m) {
                $e = is_array($m->ocr_extracted) ? $m->ocr_extracted : [];
                $parts = [];
                if (is_numeric($e['amount'] ?? null)) {
                    $parts[] = Formatting::money((float) $e['amount'], $e['currency'] ?? (CurrentHousehold::get()?->default_currency ?? 'USD'));
                }
                if (! empty($e['issued_on'])) {
                    $parts[] = (string) $e['issued_on'];
                }
                if (! empty($e['category_suggestion'])) {
                    $parts[] = (string) $e['category_suggestion'];
                }

                return [
                    'id' => $m->id,
                    'title' => $e['vendor'] ?? $m->original_name ?? __('Scan'),
                    'subtitle' => $parts === [] ? __('Awaiting review') : implode(' · ', $parts),
                    'source' => $m->source,
                    'mime' => $m->mime,
                    'created_at' => $m->created_at,
                ];
            });
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Inbox') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">
                {{ __('Bill & receipt scans awaiting a decision. Create a transaction, dismiss, or leave for later.') }}
            </p>
        </div>
        <dl class="text-right text-xs">
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Unprocessed') }}</dt>
            <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->counts['unprocessed'] }}</dd>
        </dl>
    </header>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="ibx-src" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Source') }}</label>
            <select wire:model.live="sourceFilter" id="ibx-src"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100">
                <option value="">{{ __('Any source') }}</option>
                <option value="upload">{{ __('Upload') }}</option>
                <option value="folder">{{ __('Folder rescan') }}</option>
                <option value="mail">{{ __('Mail') }}</option>
                <option value="mobile">{{ __('Mobile capture') }}</option>
            </select>
        </div>
    </form>

    @if ($bulkMessage)
        <div role="status" class="rounded-md border border-emerald-800/50 bg-emerald-950/30 px-4 py-2 text-xs text-emerald-200">
            {{ $bulkMessage }}
        </div>
    @endif

    @if ($this->items->isEmpty())
        <div class="rounded-xl border border-dashed border-emerald-900/40 bg-emerald-950/20 p-10 text-center text-sm text-emerald-300">
            {{ __('Inbox zero — no scans awaiting action.') }}
        </div>
    @else
        @php
            $selCount = count($selected);
            $itemCount = $this->items->count();
            $hasSelection = $selCount > 0;
            $allChecked = $hasSelection && $selCount === $itemCount;
            $someChecked = $hasSelection && $selCount !== $itemCount;
        @endphp
        <section aria-label="{{ __('Inbox list') }}">
            <div role="region" aria-label="{{ __('List header') }}"
                 class="sticky top-0 z-10 space-y-2 rounded-t-xl border border-b-0 {{ $hasSelection ? 'border-emerald-800/50 bg-emerald-950/30' : 'border-neutral-800 bg-neutral-900/60' }} px-4 py-2 text-[11px]">
                <div class="flex min-h-8 flex-wrap items-center gap-3">
                    <input type="checkbox"
                           wire:click="{{ $allChecked ? 'clearSelection' : 'selectAll' }}"
                           @checked($allChecked)
                           x-bind:indeterminate="{{ $someChecked ? 'true' : 'false' }}"
                           aria-label="{{ __('Select all') }}"
                           class="rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <span class="{{ $hasSelection ? 'text-emerald-100' : 'text-neutral-400' }}">
                        @if ($hasSelection)
                            {{ __(':sel of :n selected', ['sel' => $selCount, 'n' => $itemCount]) }}
                        @else
                            {{ __(':n items', ['n' => $itemCount]) }}
                        @endif
                    </span>
                    @if ($hasSelection)
                        <div class="ml-auto flex flex-wrap items-center gap-2">
                            <button type="button" wire:click="openBulkTxnForm"
                                    class="rounded-md bg-emerald-600 px-3 py-1 text-xs font-medium text-white hover:bg-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                {{ __('Create :n transactions', ['n' => $selCount]) }}
                            </button>
                            <button type="button" wire:click="dismissSelected"
                                    class="rounded-md border border-emerald-700/50 bg-emerald-900/40 px-3 py-1 text-xs font-medium text-emerald-100 hover:bg-emerald-900/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                {{ __('Dismiss :n', ['n' => $selCount]) }}
                            </button>
                            <button type="button" wire:click="bulkDelete"
                                    wire:confirm="{{ __('Delete :n items permanently?', ['n' => $selCount]) }}"
                                    class="rounded-md border border-rose-800/50 bg-rose-900/30 px-3 py-1 text-xs font-medium text-rose-100 hover:bg-rose-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                {{ __('Delete') }}
                            </button>
                            <button type="button" wire:click="clearSelection"
                                    class="rounded-md px-3 py-1 text-xs text-emerald-200 hover:bg-emerald-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                {{ __('Clear') }}
                            </button>
                        </div>
                    @endif
                </div>
                @if ($hasSelection && $showBulkTxnForm)
                    <form wire:submit.prevent="bulkCreateTransactions"
                          class="flex flex-wrap items-end gap-3 rounded-md border border-emerald-800/50 bg-emerald-950/40 px-3 py-2 text-xs">
                        <div>
                            <label for="bulk-acct" class="block text-[10px] uppercase tracking-wider text-emerald-300/80">{{ __('Account') }}</label>
                            <select wire:model="bulk_account_id" id="bulk-acct" required
                                    class="mt-1 rounded-md border border-emerald-800/50 bg-emerald-950 px-2 py-1.5 text-xs text-emerald-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                <option value="">—</option>
                                @foreach ($this->accounts as $a)
                                    <option value="{{ $a->id }}">{{ $a->name }}</option>
                                @endforeach
                            </select>
                            @error('bulk_account_id')<div role="alert" class="mt-1 text-[10px] text-rose-300">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="bulk-status" class="block text-[10px] uppercase tracking-wider text-emerald-300/80">{{ __('Status') }}</label>
                            <select wire:model="bulk_status" id="bulk-status"
                                    class="mt-1 rounded-md border border-emerald-800/50 bg-emerald-950 px-2 py-1.5 text-xs text-emerald-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                @foreach (App\Support\Enums::transactionStatuses() as $v => $l)
                                    <option value="{{ $v }}">{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit"
                                class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Create') }}
                        </button>
                        <button type="button" wire:click="cancelBulkTxnForm"
                                class="rounded-md px-3 py-1.5 text-xs text-emerald-200 hover:bg-emerald-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Cancel') }}
                        </button>
                    </form>
                @endif
            </div>
            <ul class="divide-y divide-neutral-800 rounded-b-xl border border-neutral-800 bg-neutral-900/40">
                @foreach ($this->items as $row)
                    @php($checked = in_array($row['id'], $selected, true))
                    <li class="flex items-start gap-3 px-4 py-3 text-sm">
                        <input type="checkbox"
                               wire:click="toggle({{ $row['id'] }})"
                               @checked($checked)
                               aria-label="{{ __('Select :t', ['t' => $row['title']]) }}"
                               class="mt-2 rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">

                        @if (str_starts_with((string) $row['mime'], 'image/'))
                            <a href="{{ route('records.media', ['focus' => $row['id']]) }}"
                               class="block h-10 w-10 shrink-0 overflow-hidden rounded border border-neutral-800 bg-neutral-950 hover:border-neutral-600">
                                <img src="{{ route('media.file', ['media' => $row['id']]) }}" alt=""
                                     loading="lazy" class="h-full w-full object-cover opacity-80 hover:opacity-100" />
                            </a>
                        @else
                            <span aria-hidden="true" class="block h-10 w-10 shrink-0 rounded border border-dashed border-neutral-800/60"></span>
                        @endif

                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="truncate text-neutral-100">{{ $row['title'] }}</div>
                                    <div class="truncate text-[11px] text-neutral-500">{{ $row['subtitle'] }}</div>
                                </div>
                                <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-500">{{ $row['source'] }}</span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2 text-[11px]">
                                <button type="button"
                                        wire:click="$dispatch('inspector-open', { type: 'bill', mediaId: {{ $row['id'] }} })"
                                        class="rounded-md border border-emerald-800/60 bg-emerald-900/40 px-2 py-1 font-medium text-emerald-100 hover:bg-emerald-900/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    {{ __('Create bill') }}
                                </button>
                                <button type="button"
                                        wire:click="$dispatch('inspector-open', {{ json_encode(['type' => 'transaction', 'mediaId' => $row['id']]) }})"
                                        class="rounded-md border border-emerald-800/60 bg-emerald-900/40 px-2 py-1 text-emerald-100 hover:bg-emerald-900/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    {{ __('Create transaction') }}
                                </button>
                                <button type="button" wire:click="dismissOne({{ $row['id'] }})"
                                        class="rounded-md px-2 py-1 text-neutral-500 hover:bg-neutral-800 hover:text-neutral-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    {{ __('Dismiss') }}
                                </button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>

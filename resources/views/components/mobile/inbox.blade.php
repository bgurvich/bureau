<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\InventoryItem;
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
use Livewire\Component;

new
#[Layout('components.layouts.mobile', ['title' => 'Inbox'])]
class extends Component
{
    /** @var array<int, string> composite "<kind>-<id>" keys */
    public array $selected = [];

    public bool $selectMode = false;

    public bool $showBulkTxnForm = false;

    public ?int $bulk_account_id = null;

    public string $bulk_status = 'cleared';

    public ?string $bulkMessage = null;

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->items);
        $this->selected = [];
    }

    public function toggleSelectMode(): void
    {
        $this->selectMode = ! $this->selectMode;
        if (! $this->selectMode) {
            $this->selected = [];
            $this->showBulkTxnForm = false;
        }
    }

    public function toggle(string $key): void
    {
        if (in_array($key, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$key]));
        } else {
            $this->selected[] = $key;
        }
    }

    public function selectAll(): void
    {
        $this->selected = $this->items->pluck('key')->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->showBulkTxnForm = false;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function splitKeys(array $keys): array
    {
        $mediaIds = [];
        $inventoryIds = [];
        foreach ($keys as $k) {
            [$kind, $id] = array_pad(explode('-', $k, 2), 2, '');
            if ($kind === 'media') {
                $mediaIds[] = (int) $id;
            } elseif ($kind === 'inventory') {
                $inventoryIds[] = (int) $id;
            }
        }

        return [$mediaIds, $inventoryIds];
    }

    public function dismissSelected(): void
    {
        [$mediaIds, $inventoryIds] = $this->splitKeys($this->selected);
        if ($mediaIds !== []) {
            Media::whereIn('id', $mediaIds)->update(['processed_at' => now()]);
        }
        if ($inventoryIds !== []) {
            InventoryItem::whereIn('id', $inventoryIds)->update(['processed_at' => now()]);
        }
        $this->bulkMessage = __(':n dismissed.', ['n' => count($this->selected)]);
        $this->refresh();
    }

    public function openBulkTxnForm(): void
    {
        $this->showBulkTxnForm = true;
        $this->bulkMessage = null;
    }

    public function bulkCreateTransactions(): void
    {
        $this->validate([
            'bulk_account_id' => 'required|integer|exists:accounts,id',
        ]);

        [$mediaIds] = $this->splitKeys($this->selected);
        $created = 0;
        $skipped = 0;

        foreach (Media::whereIn('id', $mediaIds)->whereNull('processed_at')->get() as $scan) {
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
            $txn->media()->attach($scan->id, ['role' => 'receipt']);
            $scan->forceFill(['processed_at' => now()])->save();
            ProjectionMatcher::attempt($txn);
            $created++;
        }

        $this->bulkMessage = __(':c created, :s skipped.', ['c' => $created, 's' => $skipped]);
        $this->showBulkTxnForm = false;
        $this->bulk_account_id = null;
        $this->refresh();
    }

    public function bulkDelete(): void
    {
        [$mediaIds, $inventoryIds] = $this->splitKeys($this->selected);
        foreach (Media::whereIn('id', $mediaIds)->get() as $m) {
            try {
                Storage::disk($m->disk ?: 'local')->delete($m->path);
            } catch (\Throwable) {
                // Best-effort file cleanup.
            }
            $m->delete();
        }
        if ($inventoryIds !== []) {
            InventoryItem::whereIn('id', $inventoryIds)->delete();
        }
        $this->bulkMessage = __(':n deleted.', ['n' => count($mediaIds) + count($inventoryIds)]);
        $this->refresh();
    }

    /**
     * @param  array<string, mixed>  $extracted
     */
    private function currency(array $extracted): string
    {
        $c = is_string($extracted['currency'] ?? null) ? strtoupper((string) $extracted['currency']) : '';

        return preg_match('/^[A-Z]{3}$/', $c)
            ? $c
            : (\App\Support\CurrentHousehold::get()?->default_currency ?? 'USD');
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
        return Account::orderBy('name')->get(['id', 'name']);
    }

    /**
     * Unprocessed media + unprocessed inventory. Newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function items(): Collection
    {
        $inventory = InventoryItem::query()
            ->with(['media' => fn ($q) => $q->wherePivot('role', 'photo')->orderByPivot('position')])
            ->whereNull('processed_at')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(fn ($i) => [
                'kind' => 'inventory',
                'key' => 'inventory-'.$i->id,
                'id' => $i->id,
                'title' => $i->name,
                'subtitle' => __('Awaiting review'),
                'created_at' => $i->created_at,
                'photo_id' => $i->media->first()?->id,
                'badge_class' => 'bg-amber-900/40 text-amber-300',
            ]);

        $media = Media::query()
            ->whereNull('processed_at')
            ->where('ocr_status', 'done')
            ->whereNotNull('ocr_extracted')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(function (Media $m) {
                $e = is_array($m->ocr_extracted) ? $m->ocr_extracted : [];
                $currency = CurrentHousehold::get()?->default_currency ?? 'USD';
                $amount = is_numeric($e['amount'] ?? null) ? Formatting::money((float) $e['amount'], $currency) : null;
                $subtitle = $amount ? ($amount.($e['vendor'] ?? '' ? ' · '.$e['vendor'] : '')) : __('Scan awaiting review');

                return [
                    'kind' => 'media',
                    'key' => 'media-'.$m->id,
                    'id' => $m->id,
                    'title' => $e['vendor'] ?? ($m->original_name ?: basename($m->path)),
                    'subtitle' => $subtitle,
                    'created_at' => $m->created_at,
                    'photo_id' => str_starts_with((string) $m->mime, 'image/') ? $m->id : null,
                    'badge_class' => 'bg-sky-900/40 text-sky-300',
                ];
            });

        return $inventory->concat($media)->sortByDesc('created_at')->values();
    }
};
?>

<div class="space-y-4">
    <header class="flex items-baseline justify-between pt-2">
        <div>
            <h1 class="text-lg font-semibold text-neutral-100">{{ __('Inbox') }}</h1>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Unprocessed scans & inventory.') }}</p>
        </div>
        <button type="button"
                wire:click="toggleSelectMode"
                class="rounded-md border border-neutral-800 bg-neutral-900 px-3 py-1 text-xs text-neutral-200 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            {{ $selectMode ? __('Done') : __('Select') }}
        </button>
    </header>

    @if ($bulkMessage)
        <div role="status" class="rounded-md border border-emerald-800/50 bg-emerald-950/30 px-3 py-2 text-xs text-emerald-200">
            {{ $bulkMessage }}
        </div>
    @endif

    @if ($selectMode && ! empty($selected))
        <div role="region" aria-label="{{ __('Bulk actions') }}" class="sticky top-0 z-10 space-y-2 rounded-lg border border-emerald-800/50 bg-emerald-950/30 px-3 py-2 text-xs text-emerald-100">
            <div class="flex items-center justify-between">
                <span>{{ __(':n selected', ['n' => count($selected)]) }}</span>
                <button type="button" wire:click="clearSelection" class="text-emerald-300 hover:underline">{{ __('Clear') }}</button>
            </div>
            @php($selectedMediaCount = collect($selected)->filter(fn ($k) => str_starts_with($k, 'media-'))->count())
            <div class="flex flex-wrap gap-2">
                @if ($selectedMediaCount > 0)
                    <button type="button" wire:click="openBulkTxnForm"
                            class="flex-1 rounded-md bg-emerald-600 px-3 py-2 font-medium text-white hover:bg-emerald-500">
                        {{ __('Create :n txns', ['n' => $selectedMediaCount]) }}
                    </button>
                @endif
                <button type="button" wire:click="dismissSelected"
                        class="flex-1 rounded-md border border-emerald-700/50 bg-emerald-900/40 px-3 py-2 font-medium hover:bg-emerald-900/60">
                    {{ __('Dismiss') }}
                </button>
                <button type="button" wire:click="bulkDelete"
                        wire:confirm="{{ __('Delete :n permanently?', ['n' => count($selected)]) }}"
                        class="rounded-md border border-rose-800/50 bg-rose-900/30 px-3 py-2 font-medium text-rose-100 hover:bg-rose-900/50">
                    {{ __('Delete') }}
                </button>
            </div>
            @if ($showBulkTxnForm)
                <form wire:submit.prevent="bulkCreateTransactions"
                      class="flex flex-col gap-2 rounded-md border border-emerald-800/50 bg-emerald-950/40 px-2 py-2">
                    <label class="flex flex-col gap-1 text-[10px] uppercase tracking-wider text-emerald-300/80">
                        {{ __('Account') }}
                        <select wire:model="bulk_account_id" required
                                class="rounded-md border border-emerald-800/50 bg-emerald-950 px-2 py-1.5 text-xs text-emerald-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <option value="">—</option>
                            @foreach ($this->accounts as $a)
                                <option value="{{ $a->id }}">{{ $a->name }}</option>
                            @endforeach
                        </select>
                        @error('bulk_account_id')<span role="alert" class="text-[10px] text-rose-300">{{ $message }}</span>@enderror
                    </label>
                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 rounded-md bg-emerald-600 px-3 py-2 font-medium text-white hover:bg-emerald-500">{{ __('Create') }}</button>
                        <button type="button" wire:click="$set('showBulkTxnForm', false)" class="rounded-md px-3 py-2 text-emerald-200 hover:bg-emerald-900/40">{{ __('Cancel') }}</button>
                    </div>
                </form>
            @endif
        </div>
    @endif

    @if ($this->items->isEmpty())
        <div class="rounded-2xl border border-dashed border-emerald-900/40 bg-emerald-950/20 p-8 text-center text-sm text-emerald-300">
            {{ __('Inbox zero.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 overflow-hidden rounded-2xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->items as $it)
                @php($checked = in_array($it['key'], $selected, true))
                <li wire:key="{{ $it['key'] }}"
                    class="flex items-start gap-3 px-3 py-3 {{ $selectMode && $checked ? 'bg-emerald-950/20' : '' }}"
                    @if ($selectMode) wire:click="toggle('{{ $it['key'] }}')" @endif>
                    @if ($selectMode)
                        <input type="checkbox" @checked($checked) aria-label="{{ __('Select :t', ['t' => $it['title']]) }}"
                               class="mt-4 rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    @endif
                    @if ($it['photo_id'])
                        <img src="{{ route('media.file', $it['photo_id']) }}"
                             alt="" loading="lazy"
                             class="h-12 w-12 shrink-0 rounded-md border border-neutral-800 bg-neutral-950 object-cover">
                    @else
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-md border border-neutral-800 bg-neutral-950 text-neutral-500">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="4" y="4" width="16" height="16" rx="2"/>
                            </svg>
                        </div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="shrink-0 rounded px-1.5 py-0.5 text-[9px] font-medium uppercase tracking-wider {{ $it['badge_class'] }}">
                                {{ $it['kind'] }}
                            </span>
                            <span class="truncate text-sm text-neutral-100">{{ $it['title'] }}</span>
                        </div>
                        <div class="mt-0.5 flex items-baseline justify-between gap-2 text-[11px] text-neutral-500">
                            <span class="truncate">{{ $it['subtitle'] }}</span>
                            <span class="shrink-0 tabular-nums">{{ $it['created_at']?->diffForHumans(short: true) }}</span>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>

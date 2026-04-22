<?php

use App\Models\InventoryItem;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Inventory'])]
class extends Component
{
    #[Url(as: 'category')]
    public string $categoryFilter = '';

    #[Url(as: 'property')]
    public string $propertyFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public bool $warrantyOnly = false;

    /** @var array<int, array<string, mixed>> */
    public array $drafts = [];

    /** @var array<int, int> ids selected for bulk actions */
    public array $selected = [];

    /** @var array<int, int> ids currently rendering the inline edit form */
    public array $editingIds = [];

    public bool $showBulk = false;

    public string $bulkNames = '';

    public ?int $bulkProperty = null;

    public string $bulkRoom = '';

    public string $bulkContainer = '';

    public ?string $bulkMessage = null;

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->items, $this->warrantyCounts, $this->totalValue, $this->properties);
    }

    public function openBulk(): void
    {
        $this->showBulk = true;
        $this->bulkMessage = null;
    }

    public function closeBulk(): void
    {
        $this->showBulk = false;
        $this->bulkNames = '';
        $this->bulkProperty = null;
        $this->bulkRoom = '';
        $this->bulkContainer = '';
        $this->bulkMessage = null;
    }

    /**
     * Walking-a-closet flow: one name per line, each becomes an InventoryItem
     * with the shared property/room/container prefilled so the user can follow
     * up per row later.
     */
    public function bulkCreate(): void
    {
        $lines = preg_split('/\r?\n/', trim($this->bulkNames)) ?: [];
        $names = array_values(array_filter(array_map('trim', $lines), fn ($s) => $s !== ''));

        if (empty($names)) {
            $this->bulkMessage = __('Type at least one name.');

            return;
        }

        $property = $this->bulkProperty ?: null;
        $room = trim($this->bulkRoom) ?: null;
        $container = trim($this->bulkContainer) ?: null;

        $ownerId = auth()->id();
        foreach ($names as $name) {
            InventoryItem::create([
                'name' => mb_substr($name, 0, 255),
                'quantity' => 1,
                'category' => 'other',
                'location_property_id' => $property,
                'room' => $room,
                'container' => $container,
                'owner_user_id' => $ownerId,
            ]);
        }

        unset($this->items, $this->warrantyCounts, $this->totalValue, $this->unprocessedCount);
        $this->bulkMessage = __(':n items added — use the "Unprocessed" filter to fill in details.', ['n' => count($names)]);
        $this->bulkNames = '';
    }

    #[Computed]
    public function items(): Collection
    {
        return InventoryItem::query()
            ->with([
                'property:id,name',
                'purchasedFrom:id,display_name',
                'media' => fn ($q) => $q->wherePivot('role', 'photo')->orderByPivot('position')->orderBy('media.created_at'),
            ])
            ->when($this->categoryFilter !== '', fn ($q) => $q->where('category', $this->categoryFilter))
            ->when($this->propertyFilter !== '', fn ($q) => $q->where('location_property_id', $this->propertyFilter))
            ->when($this->statusFilter === 'unprocessed', fn ($q) => $q->whereNull('processed_at'))
            ->when($this->statusFilter === 'processed', fn ($q) => $q->whereNotNull('processed_at'))
            ->when($this->statusFilter === 'for_sale', fn ($q) => $q->where('is_for_sale', true))
            ->when($this->warrantyOnly, fn ($q) => $q
                ->whereNotNull('warranty_expires_on')
                ->whereDate('warranty_expires_on', '>=', now()->toDateString())
            )
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('brand', 'like', $term)
                    ->orWhere('model_number', 'like', $term)
                    ->orWhere('serial_number', 'like', $term)
                );
            })
            ->orderBy($this->statusFilter === 'unprocessed' ? 'created_at' : 'category')
            ->orderBy('name')
            ->limit(500)
            ->get();
    }

    #[Computed]
    public function unprocessedCount(): int
    {
        return InventoryItem::whereNull('processed_at')->count();
    }

    public function toggleSelectAll(): void
    {
        $ids = $this->items->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->selected = count(array_diff($ids, $this->selected)) === 0
            ? []
            : $ids;
    }

    public function deleteSelected(): void
    {
        if (empty($this->selected)) {
            return;
        }

        $ids = array_values(array_filter(array_map('intval', $this->selected)));
        if (empty($ids)) {
            return;
        }

        InventoryItem::whereIn('id', $ids)->delete();

        $this->selected = [];
        $this->editingIds = array_values(array_diff($this->editingIds, $ids));
        unset($this->items, $this->warrantyCounts, $this->totalValue, $this->unprocessedCount);
    }

    /** Flip selected rows into the inline-edit layout, reusing processRow() on save. */
    public function editSelected(): void
    {
        $ids = array_values(array_filter(array_map('intval', $this->selected)));
        $this->editingIds = array_values(array_unique(array_merge($this->editingIds, $ids)));
        $this->selected = [];
    }

    public function selectAll(): void
    {
        $this->selected = $this->items->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function processRow(int $id): void
    {
        $item = InventoryItem::findOrFail($id);
        $draft = $this->drafts[$id] ?? [];

        $category = in_array(($draft['category'] ?? null), array_keys(\App\Support\Enums::inventoryCategories()), true)
            ? $draft['category']
            : ($item->category ?? 'other');

        $quantity = max(1, (int) ($draft['quantity'] ?? $item->quantity ?? 1));
        $brand = isset($draft['brand']) ? (trim((string) $draft['brand']) ?: null) : $item->brand;
        $container = isset($draft['container']) ? (trim((string) $draft['container']) ?: null) : $item->container;

        $item->update([
            'category' => $category,
            'quantity' => $quantity,
            'brand' => $brand,
            'container' => $container,
            'processed_at' => now(),
        ]);

        unset($this->drafts[$id]);
        $this->editingIds = array_values(array_diff($this->editingIds, [$id]));
        unset($this->items, $this->unprocessedCount);
    }

    #[Computed]
    public function warrantyCounts(): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $cutoff = CarbonImmutable::today()->addDays(30)->toDateString();

        return [
            'active' => InventoryItem::whereNotNull('warranty_expires_on')
                ->whereDate('warranty_expires_on', '>=', $today)
                ->count(),
            'expiring' => InventoryItem::whereNotNull('warranty_expires_on')
                ->whereDate('warranty_expires_on', '>=', $today)
                ->whereDate('warranty_expires_on', '<=', $cutoff)
                ->count(),
        ];
    }

    #[Computed]
    public function totalValue(): float
    {
        return (float) $this->items->sum('cost_amount');
    }

    #[Computed]
    public function currency(): string
    {
        return CurrentHousehold::get()?->default_currency ?? 'USD';
    }

    /** @return Collection<int, \App\Models\Property> */
    #[Computed]
    public function properties(): Collection
    {
        return \App\Models\Property::orderBy('name')->get(['id', 'name']);
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Inventory') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Household belongings — appliances, electronics, art, tools.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button"
                    wire:click="openBulk"
                    class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-200 hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ __('Bulk add') }}
            </button>
            <x-ui.new-record-button type="inventory" :label="__('New item')" shortcut="I" />
        </div>
    </header>

    @if ($showBulk)
        <div x-cloak x-transition.opacity
             class="fixed inset-0 z-40 bg-black/60"
             wire:click="closeBulk"
             aria-hidden="true"></div>

        <aside x-cloak
               role="dialog" aria-modal="true" aria-label="{{ __('Bulk add inventory') }}"
               class="fixed left-1/2 top-24 z-50 w-full max-w-lg -translate-x-1/2 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-950 shadow-2xl">
            <header class="flex items-center justify-between border-b border-neutral-800 px-5 py-3">
                <h2 class="text-sm font-semibold text-neutral-100">{{ __('Bulk add inventory') }}</h2>
                <button type="button" wire:click="closeBulk" aria-label="{{ __('Close') }}"
                        class="rounded-md p-1 text-neutral-500 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </button>
            </header>
            <div class="space-y-4 px-5 py-4">
                <p class="text-xs text-neutral-500">{{ __('One name per line. Everything shares the same location — open each row later to fill in details.') }}</p>

                <div>
                    <label for="bk-names" class="mb-1 block text-xs text-neutral-400">{{ __('Names') }}</label>
                    <textarea wire:model="bulkNames" id="bk-names" rows="8"
                              placeholder="{{ __('Hair dryer
Gray socks
Passport holder
…') }}"
                              class="w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 font-mono text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label for="bk-prop" class="mb-1 block text-xs text-neutral-400">{{ __('Property') }}</label>
                        <select wire:model="bulkProperty" id="bk-prop"
                                class="w-full rounded-md border border-neutral-700 bg-neutral-900 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <option value="">—</option>
                            @foreach ($this->properties as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="bk-room" class="mb-1 block text-xs text-neutral-400">{{ __('Room') }}</label>
                        <input wire:model="bulkRoom" id="bk-room" type="text"
                               class="w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    </div>
                    <div>
                        <label for="bk-container" class="mb-1 block text-xs text-neutral-400">{{ __('Container') }}</label>
                        <input wire:model="bulkContainer" id="bk-container" type="text"
                               class="w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    </div>
                </div>

                @if ($bulkMessage)
                    <div role="status" class="rounded-md border border-emerald-800/40 bg-emerald-900/20 px-3 py-2 text-xs text-emerald-300">
                        {{ $bulkMessage }}
                    </div>
                @endif
            </div>
            <footer class="flex items-center justify-end gap-2 border-t border-neutral-800 bg-neutral-900/50 px-5 py-3">
                <button type="button" wire:click="closeBulk"
                        class="rounded-md px-3 py-1.5 text-xs text-neutral-400 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Done') }}
                </button>
                <button type="button" wire:click="bulkCreate"
                        class="rounded-md bg-neutral-100 px-4 py-1.5 text-xs font-medium text-neutral-900 transition hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <span wire:loading.remove wire:target="bulkCreate">{{ __('Create') }}</span>
                    <span wire:loading wire:target="bulkCreate">{{ __('Saving…') }}</span>
                </button>
            </footer>
        </aside>
    @endif

    <dl class="flex gap-5 text-xs">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Warranties active') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->warrantyCounts['active'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Expiring ≤ 30d') }}</dt>
                <dd class="mt-0.5 tabular-nums {{ $this->warrantyCounts['expiring'] > 0 ? 'text-amber-400' : 'text-neutral-500' }}">{{ $this->warrantyCounts['expiring'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Shown value') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ Formatting::money($this->totalValue, $this->currency) }}</dd>
            </div>
    </dl>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="in-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="in-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Name, brand, serial…') }}">
        </div>
        <div>
            <label for="in-cat" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Category') }}</label>
            <select wire:model.live="categoryFilter" id="in-cat"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (App\Support\Enums::inventoryCategories() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        @if ($this->properties->isNotEmpty())
            <div>
                <label for="in-prop" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Location') }}</label>
                <select wire:model.live="propertyFilter" id="in-prop"
                        class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($this->properties as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div>
            <label for="in-status" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Status') }}</label>
            <select wire:model.live="statusFilter" id="in-status"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                <option value="unprocessed">{{ __('Unprocessed') }}@if ($this->unprocessedCount > 0) ({{ $this->unprocessedCount }})@endif</option>
                <option value="processed">{{ __('Processed') }}</option>
                <option value="for_sale">{{ __('For sale') }}</option>
            </select>
        </div>
        <label class="flex items-center gap-2 text-xs text-neutral-300">
            <input wire:model.live="warrantyOnly" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('With active warranty') }}
        </label>
    </form>

    @if ($this->items->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No items match those filters.') }}
        </div>
    @else
        @php
            $selCount = count($selected);
            $itemCount = $this->items->count();
            $hasSelection = $selCount > 0;
            $allChecked = $hasSelection && $selCount === $itemCount;
            $someChecked = $hasSelection && $selCount !== $itemCount;
        @endphp
        <section aria-label="{{ __('Inventory list') }}">
        <div role="region" aria-label="{{ __('List header') }}"
             class="sticky top-0 z-10 rounded-t-xl border border-b-0 {{ $hasSelection ? 'border-amber-800/50 bg-amber-950/30' : 'border-neutral-800 bg-neutral-900/60' }} px-4 py-2 text-[11px]">
            <div class="flex min-h-8 flex-wrap items-center gap-3">
                <input type="checkbox"
                       wire:key="inv-select-all-{{ $selCount }}-{{ $itemCount }}-{{ $allChecked ? 1 : 0 }}"
                       wire:click="{{ $allChecked ? 'clearSelection' : 'selectAll' }}"
                       @checked($allChecked)
                       x-bind:indeterminate="{{ $someChecked ? 'true' : 'false' }}"
                       aria-label="{{ __('Select all') }}"
                       class="rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <span class="{{ $hasSelection ? 'text-amber-100' : 'text-neutral-400' }}">
                    @if ($hasSelection)
                        {{ __(':sel of :n selected', ['sel' => count($selected), 'n' => $this->items->count()]) }}
                    @else
                        {{ __(':n items', ['n' => $this->items->count()]) }}
                    @endif
                </span>
                @if ($hasSelection)
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <button type="button" wire:click="editSelected"
                                class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1 text-xs text-neutral-100 hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Edit selected') }}
                        </button>
                        <button type="button"
                                wire:click="deleteSelected"
                                wire:confirm="{{ __('Delete the :n selected items? This cannot be undone.', ['n' => count($selected)]) }}"
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
        <ul class="divide-y divide-neutral-800 rounded-b-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->items as $i)
                @php
                    $editable = $statusFilter === 'unprocessed' || in_array($i->id, $editingIds, true);
                    if ($editable) {
                        $drafts[$i->id] ??= [
                            'quantity' => (int) $i->quantity,
                            'category' => $i->category ?? 'other',
                            'brand' => $i->brand ?? '',
                            'container' => $i->container ?? '',
                        ];
                    }
                @endphp
                <li wire:key="inv-row-{{ $i->id }}" class="flex items-start gap-3 px-4 py-2">
                    <input type="checkbox" wire:model.live="selected" value="{{ $i->id }}"
                           aria-label="{{ __('Select :name', ['name' => $i->name]) }}"
                           class="mt-2 rounded border-neutral-700 bg-neutral-950">

                    @if ($editable)
                        @include('partials.inventory-thumb', ['i' => $i, 'size' => 'lg'])
                        <div class="grid w-full grid-cols-[minmax(0,1fr)_5.5rem_7rem_7rem_7rem_auto] items-center gap-2 text-sm">
                            <div class="min-w-0">
                                <div class="truncate text-neutral-100">{{ $i->name }}</div>
                                @if ($i->room || $i->container || $i->property)
                                    <div class="truncate text-[11px] text-neutral-500">
                                        {{ $i->property?->name ?? '—' }}@if($i->room) / {{ $i->room }}@endif@if($i->container) / {{ $i->container }}@endif
                                    </div>
                                @endif
                            </div>
                            <input wire:model="drafts.{{ $i->id }}.quantity"
                                   type="number" min="1" step="1"
                                   aria-label="{{ __('Quantity') }}"
                                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <select wire:model="drafts.{{ $i->id }}.category"
                                    aria-label="{{ __('Category') }}"
                                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                @foreach (App\Support\Enums::inventoryCategories() as $v => $l)
                                    <option value="{{ $v }}">{{ $l }}</option>
                                @endforeach
                            </select>
                            <input wire:model="drafts.{{ $i->id }}.brand"
                                   type="text" aria-label="{{ __('Brand') }}" placeholder="{{ __('Brand') }}"
                                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <input wire:model="drafts.{{ $i->id }}.container"
                                   type="text" aria-label="{{ __('Container') }}" placeholder="{{ __('Container') }}"
                                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <div class="flex items-center gap-1">
                                <button type="button"
                                        wire:click="$dispatch('inspector-open', {{ json_encode(['type' => 'inventory', 'id' => $i->id]) }})"
                                        title="{{ __('Open Inspector') }}"
                                        class="rounded-md border border-neutral-800 px-2 py-1.5 text-xs text-neutral-400 hover:border-neutral-600 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    <span aria-hidden="true">⋯</span>
                                </button>
                                <button type="button"
                                        wire:click="processRow({{ $i->id }})"
                                        class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-emerald-50 hover:bg-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    {{ __('Done') }}
                                </button>
                            </div>
                        </div>
                    @else
                        @php
                            $warranty = $i->warranty_expires_on ? CarbonImmutable::parse($i->warranty_expires_on) : null;
                            $daysLeft = $warranty ? (int) now()->startOfDay()->diffInDays($warranty, absolute: false) : null;
                            $warrantyClass = match (true) {
                                $daysLeft === null => 'text-neutral-500',
                                $daysLeft < 0 => 'text-neutral-500',
                                $daysLeft <= 30 => 'text-amber-400',
                                $daysLeft <= 90 => 'text-neutral-300',
                                default => 'text-neutral-500',
                            };
                        @endphp
                        @include('partials.inventory-thumb', ['i' => $i, 'size' => 'sm'])
                        <button type="button"
                                wire:click="$dispatch('inspector-open', {{ json_encode(['type' => 'inventory', 'id' => $i->id]) }})"
                                class="flex w-full items-start gap-4 text-left text-sm transition hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline gap-2">
                                    <span class="truncate text-neutral-100">{{ $i->name }}</span>
                                    @if ($i->quantity > 1)
                                        <span class="shrink-0 text-xs tabular-nums text-neutral-400">× {{ $i->quantity }}</span>
                                    @endif
                                    @if ($i->category)
                                        <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ $i->category }}</span>
                                    @endif
                                    @if ($i->is_for_sale)
                                        <span class="shrink-0 rounded bg-emerald-900/40 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-emerald-300">{{ __('for sale') }}</span>
                                    @endif
                                </div>
                                <div class="mt-0.5 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                                    @if ($i->brand)
                                        <span>{{ $i->brand }}@if($i->model_number) · {{ $i->model_number }}@endif</span>
                                    @endif
                                    @if ($i->serial_number)
                                        <span class="tabular-nums">SN {{ $i->serial_number }}</span>
                                    @endif
                                    @if ($i->property || $i->container)
                                        <span>{{ $i->property?->name ?? '—' }}@if($i->room) / {{ $i->room }}@endif@if($i->container) / {{ $i->container }}@endif</span>
                                    @endif
                                    @if ($i->purchased_on)
                                        <span>{{ __('Bought') }} {{ Formatting::date($i->purchased_on) }}</span>
                                    @endif
                                    @if ($i->return_by)
                                        @php
                                            $returnDays = (int) now()->startOfDay()->diffInDays($i->return_by, absolute: false);
                                            $returnClass = match (true) {
                                                $returnDays < 0 => 'text-neutral-600',
                                                $returnDays <= 7 => 'text-rose-400',
                                                $returnDays <= 30 => 'text-amber-400',
                                                default => 'text-neutral-400',
                                            };
                                        @endphp
                                        <span class="{{ $returnClass }}">@if ($returnDays < 0){{ __('return closed') }}@else{{ __('Return by') }} {{ Formatting::date($i->return_by) }} · {{ $returnDays }}d @endif</span>
                                    @endif
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                @if ($i->cost_amount !== null)
                                    <div class="text-sm tabular-nums text-neutral-100">
                                        {{ Formatting::money((float) $i->cost_amount, $i->cost_currency ?? $this->currency) }}
                                    </div>
                                @endif
                                @if ($warranty)
                                    <div class="text-[10px] uppercase tracking-wider {{ $warrantyClass }}">
                                        @if ($daysLeft < 0)
                                            {{ __('warranty ended') }}
                                        @else
                                            {{ __('warranty') }} {{ Formatting::date($warranty) }}
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
        </section>
    @endif
</div>

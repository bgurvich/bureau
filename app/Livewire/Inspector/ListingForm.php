<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Livewire\Inspector\Concerns\WithCounterpartyPicker;
use App\Models\InventoryItem;
use App\Models\Listing;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Listing inspector — records one (item, platform) posting. Manual
 * data entry for now; the auto-post path (eBay Sell API, Craigslist
 * bulkpost XML) will add optional publishing hooks later.
 */
class ListingForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasTagList;
    use WithCounterpartyPicker;

    public ?int $id = null;

    public string $title = '';

    public string $platform = 'ebay';

    public string $status = 'draft';

    public string $price = '';

    public string $currency = 'USD';

    public string $external_url = '';

    public string $external_id = '';

    public string $posted_on = '';

    public string $expires_on = '';

    public string $ended_on = '';

    public string $sold_for = '';

    public ?int $sold_to_contact_id = null;

    public ?int $inventory_item_id = null;

    public string $notes = '';

    public function mount(?int $id = null, ?int $parentId = null): void
    {
        $this->id = $id;
        $household = CurrentHousehold::get();
        $this->currency = $household?->default_currency ?: 'USD';

        if ($id !== null) {
            $l = Listing::findOrFail($id);
            $this->title = (string) $l->title;
            $this->platform = (string) $l->platform;
            $this->status = (string) $l->status;
            $this->price = $l->price !== null ? (string) $l->price : '';
            $this->currency = $l->currency ?: $this->currency;
            $this->external_url = (string) ($l->external_url ?? '');
            $this->external_id = (string) ($l->external_id ?? '');
            $this->posted_on = $l->posted_on ? $l->posted_on->toDateString() : '';
            $this->expires_on = $l->expires_on ? $l->expires_on->toDateString() : '';
            $this->ended_on = $l->ended_on ? $l->ended_on->toDateString() : '';
            $this->sold_for = $l->sold_for !== null ? (string) $l->sold_for : '';
            $this->sold_to_contact_id = $l->sold_to_contact_id;
            $this->inventory_item_id = $l->inventory_item_id;
            $this->notes = (string) ($l->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        } elseif ($parentId !== null) {
            // Prefill from the source inventory item when the user clicks
            // "List this item" off the inventory record.
            $item = InventoryItem::find($parentId);
            if ($item) {
                $this->inventory_item_id = $item->id;
                $this->title = (string) $item->name;
            }
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'title' => 'required|string|max:255',
            'platform' => ['required', Rule::in(array_keys(Enums::inventoryListingPlatforms()))],
            'status' => ['required', Rule::in(array_keys(Enums::listingStatuses()))],
            'price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'external_url' => 'nullable|url|max:2048',
            'external_id' => 'nullable|string|max:128',
            'posted_on' => 'nullable|date',
            'expires_on' => ['nullable', 'date', 'after_or_equal:posted_on'],
            'ended_on' => 'nullable|date',
            'sold_for' => 'nullable|numeric|min:0',
            'sold_to_contact_id' => 'nullable|integer|exists:contacts,id',
            'inventory_item_id' => 'nullable|integer|exists:inventory_items,id',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'title' => $data['title'],
            'platform' => $data['platform'],
            'status' => $data['status'],
            'price' => $data['price'] !== '' ? (float) $data['price'] : null,
            'currency' => $data['currency'] ?: null,
            'external_url' => $data['external_url'] ?: null,
            'external_id' => $data['external_id'] ?: null,
            'posted_on' => $data['posted_on'] ?: null,
            'expires_on' => $data['expires_on'] ?: null,
            'ended_on' => $data['ended_on'] ?: null,
            'sold_for' => $data['sold_for'] !== '' ? (float) $data['sold_for'] : null,
            'sold_to_contact_id' => $data['sold_to_contact_id'] ?: null,
            'inventory_item_id' => $data['inventory_item_id'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        // Auto-stamp ended_on when the listing transitions out of live /
        // draft — keeps the history honest without asking the user to
        // type the date every time.
        if (in_array($data['status'], ['sold', 'expired', 'cancelled'], true) && ! $payload['ended_on']) {
            $payload['ended_on'] = now()->toDateString();
        }

        if ($this->id !== null) {
            Listing::findOrFail($this->id)->update($payload);
            $this->persistAdminOwner();
        } else {
            $payload['user_id'] = auth()->id();
            $this->id = (int) Listing::create($payload)->id;
        }

        $this->persistTagList();

        $this->finalizeSave();
    }

    /** @return Collection<int, InventoryItem> */
    #[Computed]
    public function inventoryItems(): Collection
    {
        /** @var Collection<int, InventoryItem> $list */
        $list = InventoryItem::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return $list;
    }

    protected function adminOwnerClass(): ?string
    {
        return Listing::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'user_id';
    }

    protected function defaultCounterpartyModelKey(): string
    {
        return 'sold_to_contact_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.listing-form');
    }
}

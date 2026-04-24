<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasPhotos;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Livewire\Inspector\Concerns\WithCounterpartyPicker;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\Property;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Inventory form. Largest asset surface by field count —
 * identity (name/brand/model/serial), location (property + room +
 * container), purchase history, warranty window, return-by date for
 * recent buys, the shared disposition block AND a for-sale listing
 * block (asking price + platform + url + posted-at). Overrides
 * ensureDraftForPhoto so the photo-first mobile-capture flow can
 * stamp a placeholder record and attach scans before the form is
 * fully filled.
 */
class InventoryForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasPhotos;
    use HasTagList;
    use WithCounterpartyPicker;

    public ?int $id = null;

    public string $type = 'inventory';

    public string $inventory_name = '';

    public int $inventory_quantity = 1;

    public string $inventory_category = 'other';

    public ?int $inventory_property_id = null;

    public ?int $inventory_location_id = null;

    public string $inventory_room = '';

    public string $inventory_container = '';

    public string $inventory_brand = '';

    public string $inventory_model_number = '';

    public string $inventory_serial_number = '';

    public string $inventory_purchased_on = '';

    public string $inventory_cost_amount = '';

    public string $inventory_cost_currency = 'USD';

    public string $inventory_warranty_expires_on = '';

    public ?int $inventory_vendor_id = null;

    public string $inventory_order_number = '';

    public string $inventory_return_by = '';

    public string $inventory_disposed_on = '';

    public bool $inventory_is_for_sale = false;

    public string $inventory_listing_asking_amount = '';

    public string $inventory_listing_asking_currency = 'USD';

    public string $inventory_listing_platform = '';

    public string $inventory_listing_url = '';

    public string $inventory_listing_posted_at = '';

    // Shared disposition block.
    public string $disposition = '';

    public string $sale_amount = '';

    public string $sale_currency = 'USD';

    public ?int $buyer_contact_id = null;

    public string $notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        $household = CurrentHousehold::get();
        $householdCurrency = $household?->default_currency ?: 'USD';

        if ($id !== null) {
            $i = InventoryItem::findOrFail($id);
            $this->inventory_name = (string) $i->name;
            $this->inventory_quantity = (int) ($i->quantity ?? 1);
            $this->inventory_category = $i->category ?: 'other';
            $this->inventory_property_id = $i->location_property_id;
            $this->inventory_location_id = $i->location_id;
            $this->inventory_room = (string) ($i->room ?? '');
            $this->inventory_container = (string) ($i->container ?? '');
            $this->inventory_brand = (string) ($i->brand ?? '');
            $this->inventory_model_number = (string) ($i->model_number ?? '');
            $this->inventory_serial_number = (string) ($i->serial_number ?? '');
            $this->inventory_purchased_on = $i->purchased_on ? $i->purchased_on->toDateString() : '';
            $this->inventory_cost_amount = $i->cost_amount !== null ? (string) $i->cost_amount : '';
            $this->inventory_cost_currency = $i->cost_currency ?: $householdCurrency;
            $this->inventory_warranty_expires_on = $i->warranty_expires_on ? $i->warranty_expires_on->toDateString() : '';
            $this->inventory_vendor_id = $i->purchased_from_contact_id;
            $this->inventory_order_number = (string) ($i->order_number ?? '');
            $this->inventory_return_by = $i->return_by ? $i->return_by->toDateString() : '';
            $this->inventory_disposed_on = $i->disposed_on ? $i->disposed_on->toDateString() : '';
            $this->disposition = (string) ($i->disposition ?? '');
            $saleAmount = $i->getAttribute('sale_amount');
            $this->sale_amount = $saleAmount !== null ? (string) $saleAmount : '';
            $this->sale_currency = (string) ($i->getAttribute('sale_currency') ?: $householdCurrency);
            $buyerId = $i->getAttribute('buyer_contact_id');
            $this->buyer_contact_id = $buyerId !== null ? (int) $buyerId : null;
            $this->inventory_is_for_sale = (bool) $i->is_for_sale;
            $this->inventory_listing_asking_amount = $i->listing_asking_amount !== null ? (string) $i->listing_asking_amount : '';
            $this->inventory_listing_asking_currency = $i->listing_asking_currency ?: $householdCurrency;
            $this->inventory_listing_platform = (string) ($i->listing_platform ?? '');
            $this->inventory_listing_url = (string) ($i->listing_url ?? '');
            $this->inventory_listing_posted_at = $i->listing_posted_at ? $i->listing_posted_at->toDateString() : '';
            $this->notes = (string) ($i->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->inventory_cost_currency = $householdCurrency;
            $this->inventory_listing_asking_currency = $householdCurrency;
            $this->sale_currency = $householdCurrency;
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'inventory_name' => 'required|string|max:255',
            'inventory_quantity' => 'required|integer|min:1',
            'inventory_category' => ['nullable', Rule::in(array_keys(Enums::inventoryCategories()))],
            'inventory_property_id' => 'nullable|integer|exists:properties,id',
            'inventory_location_id' => 'nullable|integer|exists:locations,id',
            'inventory_room' => 'nullable|string|max:100',
            'inventory_container' => 'nullable|string|max:100',
            'inventory_brand' => 'nullable|string|max:100',
            'inventory_model_number' => 'nullable|string|max:100',
            'inventory_serial_number' => 'nullable|string|max:100',
            'inventory_purchased_on' => 'nullable|date',
            'inventory_cost_amount' => 'nullable|numeric',
            'inventory_cost_currency' => 'nullable|string|size:3',
            'inventory_warranty_expires_on' => 'nullable|date',
            'inventory_vendor_id' => 'nullable|integer|exists:contacts,id',
            'inventory_order_number' => 'nullable|string|max:128',
            'inventory_return_by' => 'nullable|date',
            'inventory_disposed_on' => 'nullable|date',
            'disposition' => ['nullable', Rule::in(array_keys(Enums::assetDispositions()))],
            'sale_amount' => 'nullable|numeric',
            'sale_currency' => 'nullable|string|size:3',
            'buyer_contact_id' => 'nullable|integer|exists:contacts,id',
            'inventory_is_for_sale' => 'boolean',
            'inventory_listing_asking_amount' => 'nullable|numeric',
            'inventory_listing_asking_currency' => 'nullable|string|size:3',
            'inventory_listing_platform' => ['nullable', Rule::in(array_keys(Enums::inventoryListingPlatforms()))],
            'inventory_listing_url' => 'nullable|url|max:512',
            'inventory_listing_posted_at' => 'nullable|date',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'name' => $data['inventory_name'],
            'quantity' => max(1, (int) $data['inventory_quantity']),
            'category' => $data['inventory_category'] ?: null,
            'location_property_id' => $data['inventory_property_id'] ?: null,
            'location_id' => $data['inventory_location_id'] ?: null,
            'room' => $data['inventory_room'] ?: null,
            'container' => $data['inventory_container'] ?: null,
            'brand' => $data['inventory_brand'] ?: null,
            'model_number' => $data['inventory_model_number'] ?: null,
            'serial_number' => $data['inventory_serial_number'] ?: null,
            'purchased_on' => $data['inventory_purchased_on'] ?: null,
            'cost_amount' => $data['inventory_cost_amount'] !== '' ? (float) $data['inventory_cost_amount'] : null,
            'cost_currency' => $data['inventory_cost_currency'] ?: null,
            'warranty_expires_on' => $data['inventory_warranty_expires_on'] ?: null,
            'purchased_from_contact_id' => $data['inventory_vendor_id'] ?: null,
            'order_number' => $data['inventory_order_number'] ?: null,
            'return_by' => $data['inventory_return_by'] ?: null,
            'processed_at' => now(),
            'disposed_on' => $data['inventory_disposed_on'] ?: null,
            'disposition' => $data['disposition'] ?: null,
            'sale_amount' => $data['sale_amount'] !== '' ? (float) $data['sale_amount'] : null,
            'sale_currency' => $data['sale_currency'] ?: null,
            'buyer_contact_id' => $data['buyer_contact_id'] ?: null,
            'is_for_sale' => (bool) ($data['inventory_is_for_sale'] ?? false),
            'listing_asking_amount' => $data['inventory_listing_asking_amount'] !== '' ? (float) $data['inventory_listing_asking_amount'] : null,
            'listing_asking_currency' => $data['inventory_listing_asking_currency'] ?: null,
            'listing_platform' => $data['inventory_listing_platform'] ?: null,
            'listing_url' => $data['inventory_listing_url'] ?: null,
            'listing_posted_at' => $data['inventory_listing_posted_at'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            InventoryItem::findOrFail($this->id)->update($payload);
        } else {
            $payload['owner_user_id'] = auth()->id();
            $this->id = (int) InventoryItem::create($payload)->id;
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->finalizeSave();
    }

    /** @return Collection<int, Property> */
    #[Computed]
    public function propertyOptions(): Collection
    {
        /** @var Collection<int, Property> $list */
        $list = Property::orderBy('name')->get(['id', 'name']);

        return $list;
    }

    /**
     * Flat picker options built by walking each location's ancestor
     * chain so selecting "Desk Drawer" reads as "House › Office ›
     * Desk Drawer" in the dropdown. Labels collapse to just the name
     * when the location has no parent, keeping root names readable.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function locationPickerOptions(): array
    {
        return Location::with('parent.parent.parent.parent') // 4 levels of eager-load — deeper paths fall back to a runtime query per breadcrumb call
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn ($l) => [$l->id => $l->breadcrumb()])
            ->all();
    }

    /**
     * Inline creation hook from the location searchable-select — spawns
     * a root-level Location by that name. Users can reparent it later
     * from the Locations manager; inline create keeps the flow fast
     * when adding inventory items in bulk.
     */
    public function createLocation(string $name, ?string $modelKey = null): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $location = Location::create([
            'name' => $name,
            'kind' => 'room',
            'property_id' => $this->inventory_property_id,
        ]);

        $targetKey = $modelKey && property_exists($this, $modelKey)
            ? $modelKey
            : 'inventory_location_id';
        $this->{$targetKey} = $location->id;
        unset($this->locationPickerOptions);

        $this->dispatch(
            'ss-option-added',
            model: $targetKey,
            id: $location->id,
            label: $location->breadcrumb(),
        );
    }

    protected function adminOwnerClass(): ?string
    {
        return InventoryItem::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'owner_user_id';
    }

    /**
     * Inventory supports photo-first creation — on the mobile capture
     * flow the user snaps a shelf photo and fills details later.
     */
    protected function ensureDraftForPhoto(): void
    {
        $name = trim($this->inventory_name) !== ''
            ? $this->inventory_name
            : __('Captured :when', ['when' => now()->format('M j, H:i')]);

        $item = InventoryItem::create([
            'name' => mb_substr($name, 0, 255),
            'quantity' => max(1, $this->inventory_quantity ?: 1),
            'category' => $this->inventory_category ?: 'other',
            'owner_user_id' => auth()->id(),
        ]);

        $this->id = (int) $item->id;
        $this->loadAdminMeta();
    }

    protected function defaultCounterpartyModelKey(): string
    {
        return 'inventory_vendor_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.inventory-form');
    }
}

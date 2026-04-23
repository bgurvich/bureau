<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasPhotos;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Livewire\Inspector\Concerns\WithCounterpartyPicker;
use App\Models\Property;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Property form. Home / land / rental — name + address,
 * acquisition, optional size, the shared disposition block. Photos
 * via HasPhotos; Property uses HasMedia.
 */
class PropertyForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasPhotos;
    use HasTagList;
    use WithCounterpartyPicker;

    public ?int $id = null;

    public string $type = 'property';

    public string $property_kind = 'home';

    public string $property_name = '';

    public string $property_address_line1 = '';

    public string $property_address_city = '';

    public string $property_address_region = '';

    public string $property_address_postcode = '';

    public string $property_acquired_on = '';

    public string $property_purchase_price = '';

    public string $property_purchase_currency = 'USD';

    public string $property_size_value = '';

    public string $property_size_unit = 'sqft';

    public string $property_disposed_on = '';

    // Shared disposition block (same field names as disposition.blade.php).
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
            $p = Property::findOrFail($id);
            $this->property_kind = (string) $p->kind;
            $this->property_name = (string) $p->name;
            $addr = is_array($p->address) ? $p->address : [];
            $this->property_address_line1 = (string) ($addr['line1'] ?? '');
            $this->property_address_city = (string) ($addr['city'] ?? '');
            $this->property_address_region = (string) ($addr['region'] ?? '');
            $this->property_address_postcode = (string) ($addr['postcode'] ?? '');
            $this->property_acquired_on = $p->acquired_on ? $p->acquired_on->toDateString() : '';
            $this->property_purchase_price = $p->purchase_price !== null ? (string) $p->purchase_price : '';
            $this->property_purchase_currency = $p->purchase_currency ?: $householdCurrency;
            $this->property_size_value = $p->size_value !== null ? (string) $p->size_value : '';
            $this->property_size_unit = $p->size_unit ?: 'sqft';
            $this->property_disposed_on = $p->disposed_on ? $p->disposed_on->toDateString() : '';
            $this->disposition = (string) ($p->disposition ?? '');
            $saleAmount = $p->getAttribute('sale_amount');
            $this->sale_amount = $saleAmount !== null ? (string) $saleAmount : '';
            $this->sale_currency = (string) ($p->getAttribute('sale_currency') ?: $householdCurrency);
            $buyerId = $p->getAttribute('buyer_contact_id');
            $this->buyer_contact_id = $buyerId !== null ? (int) $buyerId : null;
            $this->notes = (string) ($p->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->property_purchase_currency = $householdCurrency;
            $this->sale_currency = $householdCurrency;
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'property_kind' => ['required', Rule::in(array_keys(Enums::propertyKinds()))],
            'property_name' => 'required|string|max:255',
            'property_address_line1' => 'nullable|string|max:255',
            'property_address_city' => 'nullable|string|max:255',
            'property_address_region' => 'nullable|string|max:64',
            'property_address_postcode' => 'nullable|string|max:32',
            'property_acquired_on' => 'nullable|date',
            'property_purchase_price' => 'nullable|numeric',
            'property_purchase_currency' => 'nullable|string|size:3',
            'property_size_value' => 'nullable|numeric',
            'property_size_unit' => ['nullable', Rule::in(array_keys(Enums::propertySizeUnits()))],
            'property_disposed_on' => 'nullable|date',
            'disposition' => ['nullable', Rule::in(array_keys(Enums::assetDispositions()))],
            'sale_amount' => 'nullable|numeric',
            'sale_currency' => 'nullable|string|size:3',
            'buyer_contact_id' => 'nullable|integer|exists:contacts,id',
            'notes' => 'nullable|string|max:5000',
        ]);

        $address = array_filter([
            'line1' => $data['property_address_line1'] ?: null,
            'city' => $data['property_address_city'] ?: null,
            'region' => $data['property_address_region'] ?: null,
            'postcode' => $data['property_address_postcode'] ?: null,
        ]);

        $payload = [
            'kind' => $data['property_kind'],
            'name' => $data['property_name'],
            'address' => $address ?: null,
            'acquired_on' => $data['property_acquired_on'] ?: null,
            'purchase_price' => $data['property_purchase_price'] !== '' ? (float) $data['property_purchase_price'] : null,
            'purchase_currency' => $data['property_purchase_currency'] ?: null,
            'size_value' => $data['property_size_value'] !== '' ? (float) $data['property_size_value'] : null,
            'size_unit' => $data['property_size_unit'] ?: null,
            'disposed_on' => $data['property_disposed_on'] ?: null,
            'disposition' => $data['disposition'] ?: null,
            'sale_amount' => $data['sale_amount'] !== '' ? (float) $data['sale_amount'] : null,
            'sale_currency' => $data['sale_currency'] ?: null,
            'buyer_contact_id' => $data['buyer_contact_id'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            Property::findOrFail($this->id)->update($payload);
        } else {
            $payload['primary_user_id'] = auth()->id();
            $this->id = (int) Property::create($payload)->id;
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->finalizeSave();
    }

    protected function adminOwnerClass(): ?string
    {
        return Property::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'primary_user_id';
    }

    protected function defaultCounterpartyModelKey(): string
    {
        return 'buyer_contact_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.property-form');
    }
}

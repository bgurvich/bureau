<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasPhotos;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Livewire\Inspector\Concerns\WithCounterpartyPicker;
use App\Models\Contact;
use App\Models\Vehicle;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Vehicle form. Car / motorcycle / other — holds the VIN,
 * plate + jurisdiction, odometer + unit, registration expiry + fee,
 * acquisition price, plus the shared disposition block (sold / traded
 * / totaled → sale amount + buyer contact). Photos ride along via
 * HasPhotos since Vehicle uses HasMedia.
 */
class VehicleForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasPhotos;
    use HasTagList;
    use WithCounterpartyPicker;

    public ?int $id = null;

    public string $type = 'vehicle';

    public string $vehicle_kind = 'car';

    public string $vehicle_make = '';

    public string $vehicle_model = '';

    public string $vehicle_year = '';

    public string $vehicle_color = '';

    public string $vehicle_vin = '';

    public string $vehicle_license_plate = '';

    public string $vehicle_license_jurisdiction = '';

    public string $vehicle_acquired_on = '';

    public string $vehicle_purchase_price = '';

    public string $vehicle_purchase_currency = 'USD';

    public string $vehicle_odometer = '';

    public string $vehicle_odometer_unit = 'mi';

    public string $vehicle_disposed_on = '';

    public string $vehicle_registration_expires_on = '';

    public string $vehicle_registration_fee_amount = '';

    public string $vehicle_registration_fee_currency = 'USD';

    // Shared disposition fields (same names as the partial expects so
    // the vehicle/property/inventory partial can drop in unchanged).
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
            $v = Vehicle::findOrFail($id);
            $this->vehicle_kind = (string) $v->kind;
            $this->vehicle_make = (string) ($v->make ?? '');
            $this->vehicle_model = (string) ($v->model ?? '');
            $this->vehicle_year = $v->year !== null ? (string) $v->year : '';
            $this->vehicle_color = (string) ($v->color ?? '');
            $this->vehicle_vin = (string) ($v->vin ?? '');
            $this->vehicle_license_plate = (string) ($v->license_plate ?? '');
            $this->vehicle_license_jurisdiction = (string) ($v->license_jurisdiction ?? '');
            $this->vehicle_acquired_on = $v->acquired_on ? $v->acquired_on->toDateString() : '';
            $this->vehicle_purchase_price = $v->purchase_price !== null ? (string) $v->purchase_price : '';
            $this->vehicle_purchase_currency = $v->purchase_currency ?: $householdCurrency;
            $this->vehicle_odometer = $v->odometer !== null ? (string) $v->odometer : '';
            $this->vehicle_odometer_unit = $v->odometer_unit ?: 'mi';
            $this->vehicle_registration_expires_on = $v->registration_expires_on ? $v->registration_expires_on->toDateString() : '';
            $this->vehicle_registration_fee_amount = $v->registration_fee_amount !== null ? (string) $v->registration_fee_amount : '';
            $this->vehicle_registration_fee_currency = $v->registration_fee_currency ?: $householdCurrency;
            $this->vehicle_disposed_on = $v->disposed_on ? $v->disposed_on->toDateString() : '';
            $this->disposition = (string) ($v->disposition ?? '');
            $saleAmount = $v->getAttribute('sale_amount');
            $this->sale_amount = $saleAmount !== null ? (string) $saleAmount : '';
            $this->sale_currency = (string) ($v->getAttribute('sale_currency') ?: $householdCurrency);
            $buyerId = $v->getAttribute('buyer_contact_id');
            $this->buyer_contact_id = $buyerId !== null ? (int) $buyerId : null;
            $this->notes = (string) ($v->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->vehicle_purchase_currency = $householdCurrency;
            $this->vehicle_registration_fee_currency = $householdCurrency;
            $this->sale_currency = $householdCurrency;
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'vehicle_kind' => ['required', Rule::in(array_keys(Enums::vehicleKinds()))],
            'vehicle_make' => 'nullable|string|max:100',
            'vehicle_model' => 'nullable|string|max:100',
            'vehicle_year' => 'nullable|integer|between:1900,2100',
            'vehicle_color' => 'nullable|string|max:64',
            'vehicle_vin' => 'nullable|string|max:17',
            'vehicle_license_plate' => 'nullable|string|max:32',
            'vehicle_license_jurisdiction' => 'nullable|string|max:32',
            'vehicle_acquired_on' => 'nullable|date',
            'vehicle_purchase_price' => 'nullable|numeric',
            'vehicle_purchase_currency' => 'nullable|string|size:3',
            'vehicle_odometer' => 'nullable|integer|min:0',
            'vehicle_odometer_unit' => ['nullable', Rule::in(array_keys(Enums::vehicleOdometerUnits()))],
            'vehicle_registration_expires_on' => 'nullable|date',
            'vehicle_registration_fee_amount' => 'nullable|numeric',
            'vehicle_registration_fee_currency' => 'nullable|string|size:3',
            'vehicle_disposed_on' => 'nullable|date',
            'disposition' => ['nullable', Rule::in(array_keys(Enums::assetDispositions()))],
            'sale_amount' => 'nullable|numeric',
            'sale_currency' => 'nullable|string|size:3',
            'buyer_contact_id' => 'nullable|integer|exists:contacts,id',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'kind' => $data['vehicle_kind'],
            'make' => $data['vehicle_make'] ?: null,
            'model' => $data['vehicle_model'] ?: null,
            'year' => $data['vehicle_year'] !== '' ? (int) $data['vehicle_year'] : null,
            'color' => $data['vehicle_color'] ?: null,
            'vin' => $data['vehicle_vin'] ? strtoupper($data['vehicle_vin']) : null,
            'license_plate' => $data['vehicle_license_plate'] ?: null,
            'license_jurisdiction' => $data['vehicle_license_jurisdiction'] ?: null,
            'acquired_on' => $data['vehicle_acquired_on'] ?: null,
            'purchase_price' => $data['vehicle_purchase_price'] !== '' ? (float) $data['vehicle_purchase_price'] : null,
            'purchase_currency' => $data['vehicle_purchase_currency'] ?: null,
            'odometer' => $data['vehicle_odometer'] !== '' ? (int) $data['vehicle_odometer'] : null,
            'odometer_unit' => $data['vehicle_odometer_unit'] ?: 'mi',
            'registration_expires_on' => $data['vehicle_registration_expires_on'] ?: null,
            'registration_fee_amount' => $data['vehicle_registration_fee_amount'] !== '' ? (float) $data['vehicle_registration_fee_amount'] : null,
            'registration_fee_currency' => $data['vehicle_registration_fee_currency'] ?: null,
            'disposed_on' => $data['vehicle_disposed_on'] ?: null,
            'disposition' => $data['disposition'] ?: null,
            'sale_amount' => $data['sale_amount'] !== '' ? (float) $data['sale_amount'] : null,
            'sale_currency' => $data['sale_currency'] ?: null,
            'buyer_contact_id' => $data['buyer_contact_id'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            Vehicle::findOrFail($this->id)->update($payload);
        } else {
            $payload['primary_user_id'] = auth()->id();
            $this->id = (int) Vehicle::create($payload)->id;
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->finalizeSave();
    }

    protected function adminOwnerClass(): ?string
    {
        return Vehicle::class;
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
        return view('livewire.inspector.vehicle-form');
    }
}

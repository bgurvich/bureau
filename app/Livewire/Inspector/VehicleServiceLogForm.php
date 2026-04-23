<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Livewire\Inspector\Concerns\WithCounterpartyPicker;
use App\Models\Vehicle;
use App\Models\VehicleServiceLog;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Per-vehicle service log row. `parentId` at mount seeds the vehicle_id
 * FK so the per-vehicle "+ Add service" button lands on the right
 * record. Odometer + odometer_unit auto-fill from the vehicle's
 * current values but can be overridden (common when logging a service
 * that happened months ago at a lower mileage).
 */
class VehicleServiceLogForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasTagList;
    use WithCounterpartyPicker;

    public ?int $id = null;

    public ?int $vehicle_id = null;

    public string $service_date = '';

    public string $kind = 'oil_change';

    public string $label = '';

    public string $odometer = '';

    public string $odometer_unit = 'mi';

    public string $cost = '';

    public string $currency = 'USD';

    public ?int $provider_contact_id = null;

    public string $notes = '';

    public string $next_due_on = '';

    public string $next_due_odometer = '';

    public function mount(?int $id = null, ?int $parentId = null): void
    {
        $this->id = $id;
        $household = CurrentHousehold::get();
        $householdCurrency = $household?->default_currency ?: 'USD';

        if ($id !== null) {
            $s = VehicleServiceLog::findOrFail($id);
            $this->vehicle_id = $s->vehicle_id;
            $this->service_date = $s->service_date ? $s->service_date->toDateString() : now()->toDateString();
            $this->kind = (string) $s->kind;
            $this->label = (string) ($s->label ?? '');
            $this->odometer = $s->odometer !== null ? (string) $s->odometer : '';
            $this->odometer_unit = $s->odometer_unit ?: 'mi';
            $this->cost = $s->cost !== null ? (string) $s->cost : '';
            $this->currency = $s->currency ?: $householdCurrency;
            $this->provider_contact_id = $s->provider_contact_id;
            $this->notes = (string) ($s->notes ?? '');
            $this->next_due_on = $s->next_due_on ? $s->next_due_on->toDateString() : '';
            $this->next_due_odometer = $s->next_due_odometer !== null ? (string) $s->next_due_odometer : '';
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->vehicle_id = $parentId;
            $this->service_date = now()->toDateString();
            $this->currency = $householdCurrency;
            // Pre-fill odometer + unit from the vehicle's last known
            // state — the user usually just logged a service and the
            // current reading is close to correct.
            if ($parentId !== null) {
                $v = Vehicle::find($parentId);
                if ($v) {
                    $this->odometer = $v->odometer !== null ? (string) $v->odometer : '';
                    $this->odometer_unit = $v->odometer_unit ?: 'mi';
                }
            }
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'vehicle_id' => 'required|integer|exists:vehicles,id',
            'service_date' => 'required|date',
            'kind' => ['required', Rule::in(array_keys(Enums::vehicleServiceKinds()))],
            'label' => 'nullable|string|max:255',
            'odometer' => 'nullable|integer|min:0',
            'odometer_unit' => ['nullable', Rule::in(['mi', 'km'])],
            'cost' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
            'provider_contact_id' => 'nullable|integer|exists:contacts,id',
            'notes' => 'nullable|string|max:5000',
            'next_due_on' => 'nullable|date|after_or_equal:service_date',
            'next_due_odometer' => 'nullable|integer|min:0',
        ]);

        $payload = [
            'vehicle_id' => $data['vehicle_id'],
            'service_date' => $data['service_date'],
            'kind' => $data['kind'],
            'label' => $data['label'] ?: null,
            'odometer' => $data['odometer'] !== '' ? (int) $data['odometer'] : null,
            'odometer_unit' => $data['odometer_unit'] ?: null,
            'cost' => $data['cost'] !== '' ? (float) $data['cost'] : null,
            'currency' => $data['currency'] ?: null,
            'provider_contact_id' => $data['provider_contact_id'] ?: null,
            'notes' => $data['notes'] ?: null,
            'next_due_on' => ($data['next_due_on'] ?? '') ?: null,
            'next_due_odometer' => ($data['next_due_odometer'] ?? '') !== '' ? (int) $data['next_due_odometer'] : null,
        ];

        if ($this->id !== null) {
            VehicleServiceLog::findOrFail($this->id)->update($payload);
        } else {
            $this->id = (int) VehicleServiceLog::create($payload)->id;
            $this->maybeAdvanceVehicleOdometer((int) $data['vehicle_id'], $payload['odometer']);
        }

        $this->persistTagList();

        $this->finalizeSave();
    }

    /**
     * When the service's odometer reading exceeds the vehicle's last
     * known value, roll the vehicle forward. The service log is often
     * the freshest mileage touchpoint — handing the number back keeps
     * the vehicle detail + asset valuation views current.
     */
    private function maybeAdvanceVehicleOdometer(int $vehicleId, ?int $reading): void
    {
        if ($reading === null) {
            return;
        }
        $v = Vehicle::find($vehicleId);
        if (! $v) {
            return;
        }
        $current = (int) ($v->odometer ?? 0);
        if ($reading > $current) {
            $v->forceFill(['odometer' => $reading])->save();
        }
    }

    /** @return Collection<int, Vehicle> */
    #[Computed]
    public function vehicles(): Collection
    {
        /** @var Collection<int, Vehicle> $list */
        $list = Vehicle::orderBy('make')->orderBy('model')
            ->get(['id', 'make', 'model', 'year']);

        return $list;
    }

    protected function adminOwnerClass(): ?string
    {
        return VehicleServiceLog::class;
    }

    protected function adminOwnerField(): ?string
    {
        // Service rows belong to the vehicle, not a user.
        return null;
    }

    protected function defaultCounterpartyModelKey(): string
    {
        return 'provider_contact_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.vehicle-service-log-form');
    }
}

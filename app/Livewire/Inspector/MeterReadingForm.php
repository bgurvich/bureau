<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Models\MeterReading;
use App\Models\Property;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Utility-meter reading. `parentId` at mount seeds the property_id FK
 * so the per-property "+ Add reading" flow lands on the right unit.
 * Changing `kind` auto-fills the unit from the kind-default map — the
 * user can override with any string (useful for regional/non-US units).
 */
class MeterReadingForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasTagList;

    public ?int $id = null;

    public ?int $property_id = null;

    public string $kind = 'electric';

    public string $read_on = '';

    public string $value = '';

    public string $unit = '';

    public string $notes = '';

    public function mount(?int $id = null, ?int $parentId = null): void
    {
        $this->id = $id;

        if ($id !== null) {
            $m = MeterReading::findOrFail($id);
            $this->property_id = $m->property_id;
            $this->kind = (string) $m->kind;
            $this->read_on = $m->read_on ? $m->read_on->toDateString() : now()->toDateString();
            $this->value = (string) $m->value;
            $this->unit = (string) $m->unit;
            $this->notes = (string) ($m->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->property_id = $parentId;
            $this->read_on = now()->toDateString();
            $this->unit = Enums::meterReadingDefaultUnits()[$this->kind] ?? '';
        }
    }

    /**
     * Flip the unit default when the user picks a different kind —
     * only when the unit is empty or still matches the PREVIOUS kind's
     * default (i.e. the user hasn't typed a custom value). Preserves
     * manual entries like "imperial gallons" when kinds are swapped.
     */
    public function updatedKind(string $newKind): void
    {
        $defaults = Enums::meterReadingDefaultUnits();
        $seenDefaults = array_values($defaults);
        if ($this->unit === '' || in_array($this->unit, $seenDefaults, true)) {
            $this->unit = $defaults[$newKind] ?? '';
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'property_id' => 'required|integer|exists:properties,id',
            'kind' => ['required', Rule::in(array_keys(Enums::meterReadingKinds()))],
            'read_on' => 'required|date',
            'value' => 'required|numeric',
            'unit' => 'required|string|max:16',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'property_id' => $data['property_id'],
            'kind' => $data['kind'],
            'read_on' => $data['read_on'],
            'value' => (float) $data['value'],
            'unit' => $data['unit'],
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            MeterReading::findOrFail($this->id)->update($payload);
        } else {
            $this->id = (int) MeterReading::create($payload)->id;
        }

        $this->persistTagList();

        $this->finalizeSave();
    }

    /** @return Collection<int, Property> */
    #[Computed]
    public function properties(): Collection
    {
        /** @var Collection<int, Property> $list */
        $list = Property::orderBy('name')->get(['id', 'name']);

        return $list;
    }

    protected function adminOwnerClass(): ?string
    {
        return MeterReading::class;
    }

    protected function adminOwnerField(): ?string
    {
        // Meter readings belong to the property, not a user. Admin panel
        // renders created_at/updated_at only.
        return null;
    }

    public function render(): View
    {
        return view('livewire.inspector.meter-reading-form');
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Models\PetVaccination;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted vaccination form — runs in the modal sub-entity inspector
 * (from the pet drawer's "+ Add vaccine" / row click). Parent FK
 * (`pet_id`) comes from the `petId` mount param pre-seeded by the
 * shell when subentity-edit-open carries a parentId.
 */
class PetVaccinationForm extends Component
{
    public ?int $id = null;

    public ?int $pet_id = null;

    public string $vaccine_name = '';

    public string $administered_on = '';

    public string $valid_until = '';

    public string $booster_due_on = '';

    public ?int $provider_id = null;

    public string $notes = '';

    public function mount(?int $id = null, ?int $petId = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $v = PetVaccination::findOrFail($id);
            $this->pet_id = (int) $v->pet_id;
            $this->vaccine_name = (string) ($v->vaccine_name ?? '');
            $this->administered_on = $v->administered_on ? $v->administered_on->toDateString() : '';
            $this->valid_until = $v->valid_until ? $v->valid_until->toDateString() : '';
            $this->booster_due_on = $v->booster_due_on ? $v->booster_due_on->toDateString() : '';
            $this->provider_id = $v->provider_id;
            $this->notes = (string) ($v->notes ?? '');
        } elseif ($petId !== null) {
            $this->pet_id = $petId;
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'pet_id' => 'required|integer|exists:pets,id',
            'vaccine_name' => 'required|string|max:120',
            'administered_on' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:administered_on',
            'booster_due_on' => 'nullable|date',
            'provider_id' => 'nullable|integer|exists:health_providers,id',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'pet_id' => (int) $data['pet_id'],
            'vaccine_name' => $data['vaccine_name'],
            'administered_on' => $data['administered_on'] ?: null,
            'valid_until' => $data['valid_until'] ?: null,
            'booster_due_on' => $data['booster_due_on'] ?: null,
            'provider_id' => $data['provider_id'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            PetVaccination::findOrFail($this->id)->update($payload);
        } else {
            $this->id = (int) PetVaccination::create($payload)->id;
        }

        $this->dispatch('inspector-saved', type: 'pet_vaccination', id: $this->id);
        $this->dispatch('inspector-form-saved', type: 'pet_vaccination', id: $this->id);
    }

    public function render(): View
    {
        return view('livewire.inspector.pet-vaccination-form');
    }
}

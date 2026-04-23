<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Models\Pet;
use App\Support\PetVaccineTemplates;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Pet form — pilot for the inspector.blade.php → per-type
 * split. The parent inspector shell embeds this component based on
 * $type, and mounts it with the record id (null = new). All Pet state,
 * validation, and persistence lives here; the shell owns open/close,
 * modal routing, and the Save/Cancel/Delete footer.
 *
 * Event contract with the shell:
 *   * listens `inspector-save`  — parent's Save button triggers persistence
 *   * emits   `inspector-saved` — parent closes the drawer, picker lists refresh
 *
 * No impact on other form types; they stay inlined in the shell until
 * their own pilot pass extracts them similarly.
 */
class PetForm extends Component
{
    use FinalizesSave;

    public ?int $id = null;

    public string $species = 'dog';

    public string $name = '';

    public string $breed = '';

    public string $color = '';

    public string $date_of_birth = '';

    public string $sex = '';

    public string $microchip_id = '';

    public ?int $vet_provider_id = null;

    public bool $is_active = true;

    public string $notes = '';

    public ?string $errorMessage = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $p = Pet::findOrFail($id);
            $this->species = (string) ($p->species ?? 'dog');
            $this->name = (string) ($p->name ?? '');
            $this->breed = (string) ($p->breed ?? '');
            $this->color = (string) ($p->color ?? '');
            $this->date_of_birth = $p->date_of_birth ? $p->date_of_birth->toDateString() : '';
            $this->sex = (string) ($p->sex ?? '');
            $this->microchip_id = (string) ($p->microchip_id ?? '');
            $this->vet_provider_id = $p->vet_provider_id;
            $this->is_active = (bool) $p->is_active;
            $this->notes = (string) ($p->notes ?? '');
        }
    }

    /**
     * Parent shell's Save button dispatches `inspector-save`; we catch
     * that, validate, persist, and announce `inspector-saved` so the
     * parent can close the drawer and any list/picker components can
     * refresh their data.
     */
    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'species' => ['required', Rule::in(['dog', 'cat', 'rabbit', 'ferret', 'other'])],
            'name' => 'required|string|max:120',
            'breed' => 'nullable|string|max:120',
            'color' => 'nullable|string|max:64',
            'date_of_birth' => 'nullable|date',
            'sex' => ['nullable', Rule::in(['male', 'female', 'unknown', ''])],
            'microchip_id' => 'nullable|string|max:64',
            'vet_provider_id' => 'nullable|integer|exists:health_providers,id',
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'species' => $data['species'],
            'name' => $data['name'],
            'breed' => $data['breed'] ?: null,
            'color' => $data['color'] ?: null,
            'date_of_birth' => $data['date_of_birth'] ?: null,
            'sex' => $data['sex'] ?: null,
            'microchip_id' => $data['microchip_id'] ?: null,
            'vet_provider_id' => $data['vet_provider_id'] ?: null,
            'is_active' => (bool) $data['is_active'],
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            Pet::findOrFail($this->id)->update($payload);
        } else {
            $payload['primary_owner_user_id'] = auth()->id();
            $pet = Pet::create($payload);
            $this->id = (int) $pet->id;
            // First-save only: seed required species-vaccines as
            // placeholders so the pet lands with the expected rows.
            PetVaccineTemplates::seedRequiredFor($pet);
        }

        // Two events: the general refresh-my-list signal (existing contract
        // every other hub/list is already listening on) and a scoped
        // close-my-drawer signal that only the inspector shell picks up,
        // so subentity modal saves don't accidentally close the primary.
        // The payload on inspector-form-saved lets the modal instance
        // forward to subentity-edit-saved for picker refresh.
        $this->finalizeSave();
    }

    public function render(): View
    {
        return view('livewire.inspector.pet-form');
    }
}

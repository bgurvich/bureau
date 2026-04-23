<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Models\PetCheckup;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted checkup form — modal sub-entity inspector, same shape as
 * PetVaccinationForm. Parent pet id arrives via `petId` mount param
 * pre-seeded by the shell from subentity-edit-open.
 */
class PetCheckupForm extends Component
{
    use FinalizesSave;

    public ?int $id = null;

    public ?int $pet_id = null;

    public string $kind = 'annual_checkup';

    public string $checkup_on = '';

    public string $next_due_on = '';

    public ?int $provider_id = null;

    public string $cost = '';

    public string $currency = '';

    public string $findings = '';

    public function mount(?int $id = null, ?int $petId = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $c = PetCheckup::findOrFail($id);
            $this->pet_id = (int) $c->pet_id;
            $this->kind = (string) ($c->kind ?? 'annual_checkup');
            $this->checkup_on = $c->checkup_on ? $c->checkup_on->toDateString() : '';
            $this->next_due_on = $c->next_due_on ? $c->next_due_on->toDateString() : '';
            $this->provider_id = $c->provider_id;
            $this->cost = $c->cost !== null ? (string) $c->cost : '';
            $this->currency = (string) ($c->currency ?? '');
            $this->findings = (string) ($c->findings ?? '');
        } elseif ($petId !== null) {
            $this->pet_id = $petId;
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'pet_id' => 'required|integer|exists:pets,id',
            'kind' => 'required|string|max:64',
            'checkup_on' => 'nullable|date',
            'next_due_on' => 'nullable|date|after_or_equal:checkup_on',
            'provider_id' => 'nullable|integer|exists:health_providers,id',
            'cost' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'findings' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'pet_id' => (int) $data['pet_id'],
            'kind' => $data['kind'],
            'checkup_on' => $data['checkup_on'] ?: null,
            'next_due_on' => $data['next_due_on'] ?: null,
            'provider_id' => $data['provider_id'] ?: null,
            'cost' => $data['cost'] !== '' ? (float) $data['cost'] : null,
            'currency' => $data['currency'] ?: null,
            'findings' => $data['findings'] ?: null,
        ];

        if ($this->id !== null) {
            PetCheckup::findOrFail($this->id)->update($payload);
        } else {
            $this->id = (int) PetCheckup::create($payload)->id;
        }

        $this->finalizeSave();
    }

    public function render(): View
    {
        return view('livewire.inspector.pet-checkup-form');
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Models\PetLicense;
use App\Support\CurrentHousehold;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Pet license inspector — city/county animal registrations. Opens as a
 * sub-entity modal off the Pet drawer; `petId` mount param seeds the
 * FK. expires_on is the interesting field — the Attention radar reads
 * it to surface renewals inside a 30-day window.
 */
class PetLicenseForm extends Component
{
    use FinalizesSave;

    public ?int $id = null;

    public ?int $pet_id = null;

    public string $authority = '';

    public string $license_number = '';

    public string $issued_on = '';

    public string $expires_on = '';

    public string $fee = '';

    public string $currency = 'USD';

    public string $notes = '';

    public function mount(?int $id = null, ?int $petId = null): void
    {
        $this->id = $id;
        $household = CurrentHousehold::get();
        $householdCurrency = $household?->default_currency ?: 'USD';

        if ($id !== null) {
            $l = PetLicense::findOrFail($id);
            $this->pet_id = (int) $l->pet_id;
            $this->authority = (string) $l->authority;
            $this->license_number = (string) ($l->license_number ?? '');
            $this->issued_on = $l->issued_on ? $l->issued_on->toDateString() : '';
            $this->expires_on = $l->expires_on ? $l->expires_on->toDateString() : '';
            $this->fee = $l->fee !== null ? (string) $l->fee : '';
            $this->currency = $l->currency ?: $householdCurrency;
            $this->notes = (string) ($l->notes ?? '');
        } else {
            $this->pet_id = $petId;
            $this->currency = $householdCurrency;
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        // expires_on must follow issued_on when both are set; a lone
        // expires_on (unknown issue date) is accepted.
        $expiresRules = ['nullable', 'date'];
        if ($this->issued_on !== '') {
            $expiresRules[] = 'after_or_equal:issued_on';
        }

        $data = $this->validate([
            'pet_id' => 'required|integer|exists:pets,id',
            'authority' => 'required|string|max:255',
            'license_number' => 'nullable|string|max:128',
            'issued_on' => 'nullable|date',
            'expires_on' => $expiresRules,
            'fee' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'pet_id' => $data['pet_id'],
            'authority' => $data['authority'],
            'license_number' => $data['license_number'] ?: null,
            'issued_on' => $data['issued_on'] ?: null,
            'expires_on' => $data['expires_on'] ?: null,
            'fee' => $data['fee'] !== '' ? (float) $data['fee'] : null,
            'currency' => $data['currency'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            PetLicense::findOrFail($this->id)->update($payload);
        } else {
            $this->id = (int) PetLicense::create($payload)->id;
        }

        $this->finalizeSave();
    }

    public function render(): View
    {
        return view('livewire.inspector.pet-license-form');
    }
}

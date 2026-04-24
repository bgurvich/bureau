<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Models\HealthProvider;
use App\Models\Pet;
use App\Models\PetPreventiveCare;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Pet preventive-care event. `parentId` at mount seeds the pet_id FK
 * so per-pet "+ Add preventive care" lands on the right animal. Picking
 * a kind pre-fills interval_days + next_due_on from the Enums default
 * table — user can override either.
 */
class PetPreventiveCareForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasTagList;

    public ?int $id = null;

    public ?int $pet_id = null;

    public string $kind = 'heartworm';

    public string $label = '';

    public string $applied_on = '';

    public string $interval_days = '';

    public string $next_due_on = '';

    public string $cost = '';

    public string $currency = 'USD';

    public ?int $provider_id = null;

    public string $notes = '';

    /** Tracks the kind the form mounted with so updatedKind() can
     *  tell whether the user actually changed it (avoids overwriting
     *  their interval when they open an existing row). */
    #[Locked]
    public string $initialKind = '';

    public function mount(?int $id = null, ?int $parentId = null): void
    {
        $this->id = $id;
        $household = CurrentHousehold::get();
        $this->currency = $household?->default_currency ?: 'USD';

        if ($id !== null) {
            $p = PetPreventiveCare::findOrFail($id);
            $this->pet_id = $p->pet_id;
            $this->kind = (string) $p->kind;
            $this->label = (string) ($p->label ?? '');
            $this->applied_on = $p->applied_on->toDateString();
            $this->interval_days = $p->interval_days !== null ? (string) $p->interval_days : '';
            $this->next_due_on = $p->next_due_on ? $p->next_due_on->toDateString() : '';
            $this->cost = $p->cost !== null ? (string) $p->cost : '';
            $this->currency = $p->currency ?: $this->currency;
            $this->provider_id = $p->provider_id;
            $this->notes = (string) ($p->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->pet_id = $parentId;
            $this->applied_on = now()->toDateString();
            $this->applyKindDefaults();
        }

        $this->initialKind = $this->kind;
    }

    /** When the user picks a kind on a NEW row, prefill interval +
     *  next_due_on. Don't clobber user-typed values on edit — we only
     *  react if they actively change the kind. */
    public function updatedKind(): void
    {
        if ($this->id === null || $this->kind !== $this->initialKind) {
            $this->applyKindDefaults();
        }
    }

    /** Recompute next_due_on whenever applied_on or interval_days change. */
    public function updatedAppliedOn(): void
    {
        $this->recomputeNextDue();
    }

    public function updatedIntervalDays(): void
    {
        $this->recomputeNextDue();
    }

    private function applyKindDefaults(): void
    {
        $defaults = Enums::petPreventiveCareDefaultIntervals();
        $interval = $defaults[$this->kind] ?? null;
        if ($interval !== null) {
            $this->interval_days = (string) $interval;
        } else {
            $this->interval_days = '';
        }
        $this->recomputeNextDue();
    }

    private function recomputeNextDue(): void
    {
        if ($this->applied_on === '' || $this->interval_days === '' || ! is_numeric($this->interval_days)) {
            return;
        }
        $applied = CarbonImmutable::parse($this->applied_on);
        $this->next_due_on = $applied->addDays((int) $this->interval_days)->toDateString();
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'pet_id' => 'required|integer|exists:pets,id',
            'kind' => ['required', Rule::in(array_keys(Enums::petPreventiveCareKinds()))],
            'label' => 'nullable|string|max:255',
            'applied_on' => 'required|date',
            'interval_days' => 'nullable|integer|min:1|max:3650',
            'next_due_on' => ['nullable', 'date', 'after_or_equal:applied_on'],
            'cost' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'provider_id' => 'nullable|integer|exists:health_providers,id',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'pet_id' => $data['pet_id'],
            'kind' => $data['kind'],
            'label' => $data['label'] ?: null,
            'applied_on' => $data['applied_on'],
            'interval_days' => ($data['interval_days'] ?? '') !== '' ? (int) $data['interval_days'] : null,
            'next_due_on' => ($data['next_due_on'] ?? '') ?: null,
            'cost' => ($data['cost'] ?? '') !== '' ? (float) $data['cost'] : null,
            'currency' => $data['currency'] ?: null,
            'provider_id' => $data['provider_id'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            PetPreventiveCare::findOrFail($this->id)->update($payload);
        } else {
            $this->id = (int) PetPreventiveCare::create($payload)->id;
        }

        $this->persistTagList();

        $this->finalizeSave();
    }

    /** @return Collection<int, Pet> */
    #[Computed]
    public function pets(): Collection
    {
        /** @var Collection<int, Pet> $list */
        $list = Pet::orderBy('name')->get(['id', 'name']);

        return $list;
    }

    /** @return Collection<int, HealthProvider> */
    #[Computed]
    public function providers(): Collection
    {
        /** @var Collection<int, HealthProvider> $list */
        $list = HealthProvider::orderBy('name')->get(['id', 'name']);

        return $list;
    }

    protected function adminOwnerClass(): ?string
    {
        return PetPreventiveCare::class;
    }

    protected function adminOwnerField(): ?string
    {
        // Preventive care belongs to the pet, not a user.
        return null;
    }

    public function render(): View
    {
        return view('livewire.inspector.pet-preventive-care-form');
    }
}

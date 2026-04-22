<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasPhotos;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Models\Appointment;
use App\Models\HealthProvider;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Appointment form. First consumer of HasPhotos — the
 * appointment model uses the HasMedia trait so receipt / referral /
 * report scans can ride along with the visit record. Subject is
 * polymorphic (User|Pet) but the today's UI only surfaces a "this is
 * for me" flag; the stored subject flips between the current user
 * and null accordingly.
 */
class AppointmentForm extends Component
{
    use HasAdminPanel;
    use HasPhotos;
    use HasTagList;

    public ?int $id = null;

    /**
     * Inspector type slug — the shared `fields/photos` Blade partial
     * reads this to decide whether photo-first draft creation is
     * supported. Exposed as a public property so the partial can read
     * `$this->type` without calling an accessor.
     */
    public string $type = 'appointment';

    public string $appointment_purpose = '';

    public string $appointment_starts_at = '';

    public string $appointment_ends_at = '';

    public string $appointment_location = '';

    public string $appointment_state = 'scheduled';

    public ?int $appointment_provider_id = null;

    public bool $appointment_self_subject = true;

    public string $notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $a = Appointment::findOrFail($id);
            $this->appointment_purpose = (string) ($a->purpose ?? '');
            $this->appointment_starts_at = $a->starts_at ? $a->starts_at->format('Y-m-d\TH:i') : '';
            $this->appointment_ends_at = $a->ends_at ? $a->ends_at->format('Y-m-d\TH:i') : '';
            $this->appointment_location = (string) ($a->location ?? '');
            $this->appointment_state = (string) ($a->state ?? 'scheduled');
            $this->appointment_provider_id = $a->provider_id;
            $this->appointment_self_subject = $a->subject_type === User::class
                && $a->subject_id === auth()->id();
            $this->notes = (string) ($a->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'appointment_purpose' => 'nullable|string|max:255',
            'appointment_starts_at' => 'required|date',
            'appointment_ends_at' => 'nullable|date|after:appointment_starts_at',
            'appointment_location' => 'nullable|string|max:255',
            'appointment_state' => 'nullable|in:scheduled,completed,cancelled,no_show',
            'appointment_provider_id' => 'nullable|integer|exists:health_providers,id',
            'appointment_self_subject' => 'boolean',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'purpose' => $data['appointment_purpose'] ?: null,
            'starts_at' => $data['appointment_starts_at'],
            'ends_at' => $data['appointment_ends_at'] ?: null,
            'location' => $data['appointment_location'] ?: null,
            'state' => $data['appointment_state'] ?: 'scheduled',
            'provider_id' => $data['appointment_provider_id'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($data['appointment_self_subject'] ?? false) {
            $payload['subject_type'] = User::class;
            $payload['subject_id'] = auth()->id();
        } else {
            $payload['subject_type'] = null;
            $payload['subject_id'] = null;
        }

        if ($this->id !== null) {
            Appointment::findOrFail($this->id)->update($payload);
        } else {
            $this->id = (int) Appointment::create($payload)->id;
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->dispatch('inspector-saved', type: 'appointment', id: $this->id);
        $this->dispatch('inspector-form-saved', type: 'appointment', id: $this->id);
    }

    /** @return Collection<int, HealthProvider> */
    #[Computed]
    public function healthProviders(): Collection
    {
        /** @var Collection<int, HealthProvider> $list */
        $list = HealthProvider::orderBy('name')->get(['id', 'name', 'specialty']);

        return $list;
    }

    protected function adminOwnerClass(): ?string
    {
        return Appointment::class;
    }

    protected function adminOwnerField(): ?string
    {
        // Appointments have no user_id owner column — they scope through
        // the polymorphic subject instead.
        return null;
    }

    public function render(): View
    {
        return view('livewire.inspector.appointment-form');
    }
}

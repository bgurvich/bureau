<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasPhotos;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Livewire\Inspector\Concerns\WithCounterpartyPicker;
use App\Models\Contact;
use App\Models\PhysicalMail;
use App\Support\Enums;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted PhysicalMail form. Inbox surface for paper post —
 * kind, received/processed timestamps, optional sender contact, plus
 * title/summary borrowed from the shared $title + $description.
 * Photos are the point — a mail piece is usually a scan first, other
 * fields later. HasPhotos lets photo-first draft creation work.
 */
class PhysicalMailForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasPhotos;
    use HasTagList;
    use WithCounterpartyPicker;

    public ?int $id = null;

    public string $type = 'physical_mail';

    public string $title = '';

    public string $description = '';

    public string $pm_kind = 'other';

    public string $pm_received_on = '';

    public ?int $pm_sender_id = null;

    public bool $pm_action_required = false;

    public string $pm_processed_at = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $m = PhysicalMail::findOrFail($id);
            $this->title = (string) ($m->subject ?? '');
            $this->description = (string) ($m->summary ?? '');
            $this->pm_kind = (string) ($m->kind ?? 'other');
            $this->pm_received_on = $m->received_on ? $m->received_on->toDateString() : now()->toDateString();
            $this->pm_sender_id = $m->sender_contact_id;
            $this->pm_action_required = (bool) $m->action_required;
            $this->pm_processed_at = $m->processed_at ? $m->processed_at->format('Y-m-d\TH:i') : '';
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->pm_received_on = now()->toDateString();
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'pm_kind' => ['required', Rule::in(array_keys(Enums::physicalMailKinds()))],
            'pm_received_on' => 'required|date',
            'pm_sender_id' => 'nullable|integer|exists:contacts,id',
            'pm_action_required' => 'boolean',
            'pm_processed_at' => 'nullable|date',
        ]);

        $payload = [
            'subject' => trim((string) $data['title']) ?: null,
            'summary' => trim((string) $data['description']) ?: null,
            'kind' => $data['pm_kind'],
            'received_on' => $data['pm_received_on'],
            'sender_contact_id' => $data['pm_sender_id'] ?: null,
            'action_required' => (bool) $data['pm_action_required'],
            'processed_at' => $data['pm_processed_at']
                ? CarbonImmutable::parse($data['pm_processed_at'])
                : null,
        ];

        if ($this->id !== null) {
            PhysicalMail::findOrFail($this->id)->update($payload);
        } else {
            $this->id = (int) PhysicalMail::create($payload)->id;
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->finalizeSave();
    }

    protected function adminOwnerClass(): ?string
    {
        return PhysicalMail::class;
    }

    protected function adminOwnerField(): ?string
    {
        return null;
    }

    protected function defaultCounterpartyModelKey(): string
    {
        return 'pm_sender_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.physical-mail-form');
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasSubjectRefs;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Livewire\Inspector\Concerns\WithCounterpartyPicker;
use App\Models\Project;
use App\Support\CurrentHousehold;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Project form. Time-tracker domain: billable flag shows the
 * hourly-rate row conditionally, slug auto-derives from name when blank.
 * Uses HasAdminPanel + HasTagList for the standard admin + tag-input
 * plumbing. Client picker reuses the shared contacts list with the
 * inline createCounterparty create-method. HasSubjectRefs opts the
 * project into the shared subject picker so it can be tethered to
 * Goal (or any other subject-capable model).
 */
class ProjectForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasSubjectRefs;
    use HasTagList;
    use WithCounterpartyPicker;

    public ?int $id = null;

    public string $project_name = '';

    public string $project_slug = '';

    public string $project_color = '';

    public bool $project_billable = false;

    public string $project_hourly_rate = '';

    public string $project_hourly_rate_currency = 'USD';

    public ?int $project_client_id = null;

    public bool $project_archived = false;

    public string $notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $p = Project::findOrFail($id);
            $this->project_name = (string) $p->name;
            $this->project_slug = (string) $p->slug;
            $this->project_color = (string) ($p->color ?? '');
            $this->project_billable = (bool) $p->billable;
            $this->project_hourly_rate = $p->hourly_rate !== null ? (string) $p->hourly_rate : '';
            $this->project_hourly_rate_currency = $p->hourly_rate_currency ?: 'USD';
            $this->project_client_id = $p->client_contact_id;
            $this->project_archived = (bool) $p->archived;
            $this->notes = (string) ($p->notes ?? '');
            $this->subject_refs = $this->subjectRefsFrom($p);
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $household = CurrentHousehold::get();
            $this->project_hourly_rate_currency = $household?->default_currency ?: 'USD';
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'project_name' => 'required|string|max:255',
            'project_slug' => 'nullable|string|max:255',
            'project_color' => 'nullable|string|size:7|starts_with:#',
            'project_billable' => 'boolean',
            'project_hourly_rate' => 'nullable|numeric',
            'project_hourly_rate_currency' => 'nullable|string|size:3',
            'project_client_id' => 'nullable|integer|exists:contacts,id',
            'project_archived' => 'boolean',
            'notes' => 'nullable|string|max:5000',
        ]);

        $slug = $data['project_slug'] ?: Str::slug($data['project_name']);

        $payload = [
            'name' => $data['project_name'],
            'slug' => $slug,
            'color' => $data['project_color'] ?: null,
            'billable' => (bool) $data['project_billable'],
            'hourly_rate' => $data['project_hourly_rate'] !== '' ? (float) $data['project_hourly_rate'] : null,
            'hourly_rate_currency' => $data['project_hourly_rate_currency'] ?: null,
            'client_contact_id' => $data['project_client_id'] ?: null,
            'archived' => (bool) $data['project_archived'],
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            $project = Project::findOrFail($this->id);
            $project->update($payload);
        } else {
            $payload['user_id'] = auth()->id();
            $project = Project::create($payload);
            $this->id = (int) $project->id;
        }

        $project->syncSubjects($this->parseSubjectRefs($this->subject_refs));

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->finalizeSave();
    }

    protected function adminOwnerClass(): ?string
    {
        return Project::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'user_id';
    }

    protected function defaultCounterpartyModelKey(): string
    {
        return 'project_client_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.project-form');
    }
}

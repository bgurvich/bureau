<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Models\Meeting;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Meeting form. Admin panel wires through HasAdminPanel,
 * tag input through HasTagList. Notes are a single-property include of
 * `fields/notes`, so the form just owns its own $notes string.
 */
class MeetingForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasTagList;

    public ?int $id = null;

    public string $meeting_title = '';

    public string $starts_at = '';

    public string $ends_at = '';

    public string $location = '';

    public string $meeting_url = '';

    public bool $all_day = false;

    public string $notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $m = Meeting::findOrFail($id);
            $this->meeting_title = (string) $m->title;
            $this->starts_at = $m->starts_at ? $m->starts_at->format('Y-m-d\TH:i') : '';
            $this->ends_at = $m->ends_at ? $m->ends_at->format('Y-m-d\TH:i') : '';
            $this->location = (string) ($m->location ?? '');
            $this->meeting_url = (string) ($m->url ?? '');
            $this->all_day = (bool) $m->all_day;
            $this->notes = (string) ($m->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->starts_at = now()->addDay()->startOfHour()->format('Y-m-d\TH:i');
            $this->ends_at = now()->addDay()->startOfHour()->addMinutes(30)->format('Y-m-d\TH:i');
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'meeting_title' => 'required|string|max:255',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after_or_equal:starts_at',
            'location' => 'nullable|string|max:255',
            'all_day' => 'boolean',
            'notes' => 'nullable|string|max:5000',
            'meeting_url' => 'nullable|string|max:500',
        ]);

        $payload = [
            'title' => $data['meeting_title'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'location' => $data['location'] ?: null,
            'all_day' => (bool) $data['all_day'],
            'notes' => $data['notes'] ?: null,
            'url' => $data['meeting_url'] ?: null,
        ];

        if ($this->id !== null) {
            Meeting::findOrFail($this->id)->update($payload);
        } else {
            $payload['organizer_user_id'] = auth()->id();
            $this->id = (int) Meeting::create($payload)->id;
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->finalizeSave();
    }

    protected function adminOwnerClass(): ?string
    {
        return Meeting::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'organizer_user_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.meeting-form');
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasSubjectRefs;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Models\JournalEntry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Journal entry inspector. Required: occurred_on + body. Mood is a
 * small controlled vocab surfaced as a picker; weather + location are
 * free strings for the user's own prose. Entries are private by
 * default — the household-shared flag is the less-common case
 * (e.g. "family trip recap" meant for partners to read).
 */
class JournalEntryForm extends Component
{
    use HasAdminPanel;
    use HasSubjectRefs;
    use HasTagList;

    public ?int $id = null;

    public string $occurred_on = '';

    public string $title = '';

    public string $body = '';

    public string $mood = '';

    public string $weather = '';

    public string $location = '';

    public bool $private = true;

    public function mount(?int $id = null): void
    {
        $this->id = $id;

        if ($id !== null) {
            $j = JournalEntry::findOrFail($id);
            $this->occurred_on = $j->occurred_on ? $j->occurred_on->toDateString() : now()->toDateString();
            $this->title = (string) ($j->title ?? '');
            $this->body = (string) $j->body;
            $this->mood = (string) ($j->mood ?? '');
            $this->weather = (string) ($j->weather ?? '');
            $this->location = (string) ($j->location ?? '');
            $this->private = (bool) $j->private;
            $this->subject_refs = $this->subjectRefsFrom($j);
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->occurred_on = now()->toDateString();
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'occurred_on' => 'required|date',
            'title' => 'nullable|string|max:255',
            'body' => 'required|string',
            'mood' => 'nullable|string|max:32',
            'weather' => 'nullable|string|max:64',
            'location' => 'nullable|string|max:255',
            'private' => 'boolean',
        ]);

        $payload = [
            'occurred_on' => $data['occurred_on'],
            'title' => $data['title'] ?: null,
            'body' => $data['body'],
            'mood' => $data['mood'] ?: null,
            'weather' => $data['weather'] ?: null,
            'location' => $data['location'] ?: null,
            'private' => (bool) $data['private'],
        ];

        if ($this->id !== null) {
            $entry = JournalEntry::findOrFail($this->id);
            $entry->update($payload);
            // persistAdminOwner only runs on edits — on create, the
            // payload's user_id is already set from auth()->id() and
            // we don't want the (defaulted-null) owner picker to clear
            // it on the save round-trip.
            $this->persistAdminOwner();
        } else {
            $payload['user_id'] = auth()->id();
            $entry = JournalEntry::create($payload);
            $this->id = (int) $entry->id;
        }

        call_user_func([$entry, 'syncSubjects'], $this->parseSubjectRefs($this->subject_refs));

        $this->persistTagList();

        $this->dispatch('inspector-saved', type: 'journal_entry', id: $this->id);
        $this->dispatch('inspector-form-saved', type: 'journal_entry', id: $this->id);
    }

    protected function adminOwnerClass(): ?string
    {
        return JournalEntry::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'user_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.journal-entry-form');
    }
}

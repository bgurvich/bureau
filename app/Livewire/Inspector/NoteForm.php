<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasSubjectRefs;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Models\Note;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Note form. First consumer of HasSubjectRefs — notes
 * attach polymorphically to anything in SUBJECT_KIND_MAP. Title is
 * optional; body is required. Pinned + private are simple bool
 * flags that don't need dedicated concern scaffolding.
 */
class NoteForm extends Component
{
    use HasAdminPanel;
    use HasSubjectRefs;
    use HasTagList;

    public ?int $id = null;

    public string $title = '';

    public string $body = '';

    public bool $pinned = false;

    public bool $private = false;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $n = Note::findOrFail($id);
            $this->title = (string) ($n->title ?? '');
            $this->body = (string) $n->body;
            $this->pinned = (bool) $n->pinned;
            $this->private = (bool) $n->private;
            $this->subject_refs = $this->subjectRefsFrom($n);
            $this->loadAdminMeta();
            $this->loadTagList();
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'title' => 'nullable|string|max:255',
            'body' => 'required|string',
            'pinned' => 'boolean',
            'private' => 'boolean',
        ]);

        $payload = [
            'title' => $data['title'] ?: null,
            'body' => $data['body'],
            'pinned' => (bool) $data['pinned'],
            'private' => (bool) $data['private'],
        ];

        if ($this->id !== null) {
            $note = Note::findOrFail($this->id);
            $note->update($payload);
        } else {
            $payload['user_id'] = auth()->id();
            $note = Note::create($payload);
            $this->id = (int) $note->id;
        }

        call_user_func([$note, 'syncSubjects'], $this->parseSubjectRefs($this->subject_refs));

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->dispatch('inspector-saved', type: 'note', id: $this->id);
        $this->dispatch('inspector-form-saved', type: 'note', id: $this->id);
    }

    protected function adminOwnerClass(): ?string
    {
        return Note::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'user_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.note-form');
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasSubjectRefs;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Models\Task;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Task form. Due date + priority + state + subject refs;
 * completion timestamp flips on state=='done' transitions. New tasks
 * auto-assign to the current user.
 */
class TaskForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasSubjectRefs;
    use HasTagList;

    public ?int $id = null;

    public string $title = '';

    public string $description = '';

    public string $due_at = '';

    public int $priority = 3;

    public string $state = 'open';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $t = Task::findOrFail($id);
            $this->title = (string) $t->title;
            $this->description = (string) ($t->description ?? '');
            $this->due_at = $t->due_at ? $t->due_at->format('Y-m-d\TH:i') : '';
            $this->priority = (int) $t->priority;
            $this->state = (string) $t->state;
            $this->subject_refs = $this->subjectRefsFrom($t);
            $this->loadAdminMeta();
            $this->loadTagList();
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'due_at' => 'nullable|date',
            'priority' => 'required|integer|between:1,5',
            'state' => ['required', Rule::in(array_keys(Enums::taskStates()))],
        ]);

        $data['description'] = $data['description'] ?: null;
        $data['due_at'] = $data['due_at'] ?: null;
        if ($data['state'] === 'done' && $this->id) {
            $data['completed_at'] = now();
        } elseif ($data['state'] !== 'done') {
            $data['completed_at'] = null;
        }

        if ($this->id !== null) {
            $task = Task::findOrFail($this->id);
            $task->update($data);
        } else {
            $data['assigned_user_id'] = auth()->id();
            $task = Task::create($data);
            $this->id = (int) $task->id;
        }

        call_user_func([$task, 'syncSubjects'], $this->parseSubjectRefs($this->subject_refs));

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->finalizeSave();
    }

    protected function adminOwnerClass(): ?string
    {
        return Task::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'assigned_user_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.task-form');
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasSubjectRefs;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Models\Project;
use App\Models\Task;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Task form. Due date + priority + state + subject refs;
 * completion timestamp flips on state=='done' transitions. New tasks
 * auto-assign to the current user.
 *
 * Subtasks: parent_task_id is an optional FK to another task. The
 * picker excludes the task itself + its own descendants so the user
 * can't create a cycle; the DB-level guard is in save().
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

    public ?int $parent_task_id = null;

    public ?int $project_id = null;

    public function mount(?int $id = null, ?int $parentId = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $t = Task::findOrFail($id);
            $this->title = (string) $t->title;
            $this->description = (string) ($t->description ?? '');
            $this->due_at = $t->due_at ? $t->due_at->format('Y-m-d\TH:i') : '';
            $this->priority = (int) $t->priority;
            $this->state = (string) $t->state;
            $this->parent_task_id = $t->parent_task_id;
            $this->project_id = $t->project_id;
            $this->subject_refs = $this->subjectRefsFrom($t);
            $this->loadAdminMeta();
            $this->loadTagList();
        } elseif ($parentId !== null) {
            // "Add subtask" flow pre-fills the parent so the user can
            // type title + save without picking.
            $this->parent_task_id = $parentId;
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
            'project_id' => 'nullable|integer|exists:projects,id',
            'parent_task_id' => [
                'nullable',
                'integer',
                'exists:tasks,id',
                // Self-parent is a trivial cycle; catch it here so the
                // listing's tree walk can't recurse forever.
                fn ($attr, $value, $fail) => $value === $this->id
                    ? $fail(__('A task cannot be its own parent.'))
                    : null,
                // Descendant-as-parent would create a longer cycle. Check
                // against the current task's descendant set.
                fn ($attr, $value, $fail) => $value !== null && $this->id !== null
                    && in_array($value, $this->descendantIds($this->id), true)
                    ? $fail(__('Cannot pick a subtask of this task as its parent.'))
                    : null,
            ],
        ]);

        $data['description'] = $data['description'] ?: null;
        $data['due_at'] = $data['due_at'] ?: null;
        $data['parent_task_id'] = $data['parent_task_id'] ?: null;
        $data['project_id'] = $data['project_id'] ?: null;
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

    /**
     * Project-assignment options. Archived projects are hidden from the
     * picker — if the task is already on an archived one, the form
     * shows the current value but the user has to un-archive the
     * project to keep working with it, or drop it entirely.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function projectPickerOptions(): array
    {
        return Project::query()
            ->where('archived', false)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Candidate parents for the picker. Excludes the current task and
     * its descendants so the user can't create a cycle through the UI.
     * Keeps "open" tasks only — completed/dropped tasks aren't useful
     * parents for incoming new subtasks.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function parentTaskPickerOptions(): array
    {
        $excluded = $this->id !== null
            ? array_merge([$this->id], $this->descendantIds($this->id))
            : [];

        return Task::query()
            ->whereNotIn('id', $excluded)
            ->whereIn('state', ['open', 'waiting'])
            ->orderBy('title')
            ->limit(200)
            ->pluck('title', 'id')
            ->all();
    }

    /**
     * Walks children recursively, collecting every descendant id so the
     * picker + validation rule can exclude them. Guards against bad
     * data causing infinite loops via a visited-set.
     *
     * @return array<int, int>
     */
    private function descendantIds(int $rootId): array
    {
        $visited = [];
        $queue = [$rootId];
        $out = [];
        while ($queue !== []) {
            $batch = Task::whereIn('parent_task_id', $queue)->pluck('id')->all();
            $queue = [];
            foreach ($batch as $id) {
                if (isset($visited[$id])) {
                    continue;
                }
                $visited[$id] = true;
                $out[] = $id;
                $queue[] = $id;
            }
        }

        return $out;
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

<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasSubjectRefs;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Models\Decision;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Decision record — title + context + options + chosen + rationale +
 * follow_up_on + outcome. The outcome starts empty and is filled in
 * retrospectively; follow_up_on drives the Attention-radar "time to
 * review this decision" nudge.
 */
class DecisionForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasSubjectRefs;
    use HasTagList;

    public ?int $id = null;

    public string $decided_on = '';

    public string $title = '';

    public string $context = '';

    public string $options_considered = '';

    public string $chosen = '';

    public string $rationale = '';

    public string $follow_up_on = '';

    public string $outcome = '';

    public string $notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;

        if ($id !== null) {
            $d = Decision::findOrFail($id);
            $this->decided_on = $d->decided_on ? $d->decided_on->toDateString() : now()->toDateString();
            $this->title = (string) $d->title;
            $this->context = (string) ($d->context ?? '');
            $this->options_considered = (string) ($d->options_considered ?? '');
            $this->chosen = (string) ($d->chosen ?? '');
            $this->rationale = (string) ($d->rationale ?? '');
            $this->follow_up_on = $d->follow_up_on ? $d->follow_up_on->toDateString() : '';
            $this->outcome = (string) ($d->outcome ?? '');
            $this->notes = (string) ($d->notes ?? '');
            $this->subject_refs = $this->subjectRefsFrom($d);
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->decided_on = now()->toDateString();
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        // follow_up_on only required to be after decided_on when both
        // dates are set — a lone historical decision with no follow-up
        // still validates.
        $followUpRules = ['nullable', 'date'];
        if ($this->decided_on !== '') {
            $followUpRules[] = 'after_or_equal:decided_on';
        }

        $data = $this->validate([
            'decided_on' => 'required|date',
            'title' => 'required|string|max:255',
            'context' => 'nullable|string|max:5000',
            'options_considered' => 'nullable|string|max:5000',
            'chosen' => 'nullable|string|max:500',
            'rationale' => 'nullable|string|max:5000',
            'follow_up_on' => $followUpRules,
            'outcome' => 'nullable|string|max:5000',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'decided_on' => $data['decided_on'],
            'title' => $data['title'],
            'context' => $data['context'] ?: null,
            'options_considered' => $data['options_considered'] ?: null,
            'chosen' => $data['chosen'] ?: null,
            'rationale' => $data['rationale'] ?: null,
            'follow_up_on' => $data['follow_up_on'] ?: null,
            'outcome' => $data['outcome'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            $decision = Decision::findOrFail($this->id);
            $decision->update($payload);
            $this->persistAdminOwner();
        } else {
            $payload['user_id'] = auth()->id();
            $decision = Decision::create($payload);
            $this->id = (int) $decision->id;
        }

        call_user_func([$decision, 'syncSubjects'], $this->parseSubjectRefs($this->subject_refs));

        $this->persistTagList();

        $this->finalizeSave();
    }

    protected function adminOwnerClass(): ?string
    {
        return Decision::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'user_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.decision-form');
    }
}

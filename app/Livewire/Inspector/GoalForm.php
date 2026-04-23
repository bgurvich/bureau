<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Models\Goal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Personal goal — two shapes:
 *   - target    → target_value + unit + optional target_date; classic pacing
 *   - direction → no numeric target; cadence_days nudge interval
 *
 * Switching mode on save clears the hidden side's values so a later
 * mode-flip doesn't stash stale numbers. achieved_on auto-stamps on
 * status=achieved transitions.
 */
class GoalForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasTagList;

    public ?int $id = null;

    public string $title = '';

    public string $category = 'other';

    /** target | direction */
    public string $mode = 'target';

    public string $target_value = '';

    public string $current_value = '0';

    public string $unit = '';

    public string $started_on = '';

    public string $target_date = '';

    public string $cadence_days = '';

    public string $status = 'active';

    public string $notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;

        if ($id !== null) {
            $g = Goal::findOrFail($id);
            $this->title = (string) $g->title;
            $this->category = (string) ($g->category ?: 'other');
            $this->mode = (string) ($g->mode ?: 'target');
            $this->target_value = $g->target_value !== null ? (string) $g->target_value : '';
            $this->current_value = (string) $g->current_value;
            $this->unit = (string) ($g->unit ?? '');
            $this->started_on = $g->started_on ? $g->started_on->toDateString() : '';
            $this->target_date = $g->target_date ? $g->target_date->toDateString() : '';
            $this->cadence_days = $g->cadence_days !== null ? (string) $g->cadence_days : '';
            $this->status = (string) ($g->status ?: 'active');
            $this->notes = (string) ($g->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->started_on = now()->toDateString();
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $rules = [
            'title' => 'required|string|max:255',
            'category' => 'required|string|max:32',
            'mode' => 'required|in:target,direction',
            'status' => 'required|string|in:active,paused,achieved,abandoned',
            'unit' => 'nullable|string|max:32',
            'started_on' => 'nullable|date',
            'notes' => 'nullable|string|max:5000',
        ];

        if ($this->mode === 'target') {
            $rules['target_value'] = 'required|numeric|min:0';
            $rules['current_value'] = 'required|numeric|min:0';
            $targetDateRules = ['nullable', 'date'];
            if ($this->started_on !== '') {
                $targetDateRules[] = 'after_or_equal:started_on';
            }
            $rules['target_date'] = $targetDateRules;
        } else {
            $rules['cadence_days'] = 'nullable|integer|min:1|max:365';
        }

        $data = $this->validate($rules);

        $payload = [
            'title' => $data['title'],
            'category' => $data['category'],
            'mode' => $data['mode'],
            'status' => $data['status'],
            'unit' => ($data['unit'] ?? '') ?: null,
            'started_on' => ($data['started_on'] ?? '') ?: null,
            'notes' => ($data['notes'] ?? '') ?: null,
        ];

        if ($data['mode'] === 'target') {
            $payload['target_value'] = $data['target_value'];
            $payload['current_value'] = $data['current_value'];
            $payload['target_date'] = ($data['target_date'] ?? '') ?: null;
            $payload['cadence_days'] = null;
        } else {
            // Clear the target-side fields so a target→direction flip
            // doesn't leave a stale 500/20 on the record.
            $payload['target_value'] = null;
            $payload['current_value'] = 0;
            $payload['target_date'] = null;
            $payload['cadence_days'] = ($data['cadence_days'] ?? '') !== '' ? (int) $data['cadence_days'] : null;
        }

        // achieved_on stamps on first achieved transition, clears when
        // status leaves achieved.
        if ($data['status'] === 'achieved') {
            $existing = $this->id !== null ? Goal::find($this->id) : null;
            $payload['achieved_on'] = $existing && $existing->achieved_on
                ? $existing->achieved_on->toDateString()
                : now()->toDateString();
        } else {
            $payload['achieved_on'] = null;
        }

        // Saving a direction goal counts as a reflection — bumps
        // last_reflected_at so staleness resets on every edit.
        if ($data['mode'] === 'direction') {
            $payload['last_reflected_at'] = now();
        }

        if ($this->id !== null) {
            $goal = Goal::findOrFail($this->id);
            $goal->update($payload);
            $this->persistAdminOwner();
        } else {
            $payload['user_id'] = auth()->id();
            $goal = Goal::create($payload);
            $this->id = (int) $goal->id;
        }

        $this->persistTagList();

        $this->finalizeSave();
    }

    protected function adminOwnerClass(): ?string
    {
        return Goal::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'user_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.goal-form');
    }
}

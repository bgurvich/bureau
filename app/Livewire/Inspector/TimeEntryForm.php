<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted TimeEntry form — third pilot of the per-type split.
 * Another "simple type" (no tags, no admin-owner, no subject refs) so
 * the extraction is straight: state + mount(load) + save. Picks up the
 * same `inspector-save` → `inspector-form-saved { type, id }` event
 * contract as PetForm / PetVaccinationForm / PetCheckupForm.
 *
 * Duration model: UI shows hours (decimal), the record stores
 * duration_seconds + started_at + ended_at. mount() renders the hours
 * back from seconds, save() re-inflates to UTC timestamps anchored at
 * 09:00 local on the activity date (matches the pre-refactor shape).
 */
class TimeEntryForm extends Component
{
    use FinalizesSave;

    public ?int $id = null;

    public string $activity_date = '';

    public string $hours = '';

    public ?int $project_id = null;

    public ?int $task_id = null;

    public string $description = '';

    public bool $billable = false;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $e = TimeEntry::findOrFail($id);
            $this->activity_date = $e->activity_date->toDateString();
            $this->hours = $e->duration_seconds
                ? rtrim(rtrim(number_format($e->duration_seconds / 3600, 2, '.', ''), '0'), '.')
                : '';
            $this->project_id = $e->project_id;
            $this->task_id = $e->task_id;
            $this->description = $e->description ?? '';
            $this->billable = (bool) $e->billable;
        } else {
            $this->activity_date = now()->toDateString();
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'activity_date' => 'required|date',
            'hours' => 'required|numeric|min:0.01|max:24',
            'project_id' => 'nullable|integer|exists:projects,id',
            'task_id' => 'nullable|integer|exists:tasks,id',
            'description' => 'nullable|string|max:1000',
            'billable' => 'boolean',
        ]);

        $tz = auth()->user()?->timezone ?: config('app.timezone', 'UTC');
        $durationSeconds = (int) round((float) $data['hours'] * 3600);
        $startedAt = CarbonImmutable::parse($data['activity_date'].' 09:00', $tz)->utc();
        $endedAt = $startedAt->addSeconds($durationSeconds);

        $payload = [
            'activity_date' => $data['activity_date'],
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $durationSeconds,
            'project_id' => $data['project_id'] ?: null,
            'task_id' => $data['task_id'] ?: null,
            'description' => $data['description'] ?: null,
            'billable' => (bool) ($data['billable'] ?? false),
        ];

        if ($this->id !== null) {
            TimeEntry::findOrFail($this->id)->update($payload);
        } else {
            $payload['user_id'] = auth()->id();
            $this->id = (int) TimeEntry::create($payload)->id;
        }

        $this->finalizeSave();
    }

    public function render(): View
    {
        return view('livewire.inspector.time-entry-form');
    }
}

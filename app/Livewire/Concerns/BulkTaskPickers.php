<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Goal;
use App\Models\Project;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;

/**
 * Shared state for the bulk-task form's optional Goal + Project
 * pickers. Every bulk-add host (tasks-index inline panel, tasks-bell
 * modal, mobile capture page) pulls these in so the shared
 * `<x-tasks.bulk-form>` component can wire two searchable-selects
 * without the host having to hand-roll create + options plumbing.
 *
 * Passing both to TaskBulkCreator::run() applies the selected goal
 * and project to every task created in the batch.
 */
trait BulkTaskPickers
{
    public ?int $bulkGoalId = null;

    public ?int $bulkProjectId = null;

    /** @return array<int, string> */
    #[Computed]
    public function bulkGoalOptions(): array
    {
        return Goal::query()
            ->where('status', 'active')
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    /** @return array<int, string> */
    #[Computed]
    public function bulkProjectOptions(): array
    {
        return Project::query()
            ->where('archived', false)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function createBulkGoal(string $title, ?string $modelKey = null): void
    {
        $title = trim($title);
        if ($title === '') {
            return;
        }
        $goal = Goal::firstOrCreate(
            ['title' => $title, 'status' => 'active'],
            ['mode' => 'direction', 'category' => 'other'],
        );
        $this->bulkGoalId = (int) $goal->id;
        unset($this->bulkGoalOptions);
        $this->dispatch(
            'ss-option-added',
            model: $modelKey ?: 'bulkGoalId',
            id: $goal->id,
            label: $goal->title,
        );
    }

    public function createBulkProject(string $name, ?string $modelKey = null): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        $payload = [
            'name' => $name,
            'slug' => Str::slug($name) ?: Str::random(8),
            'user_id' => auth()->id(),
        ];
        if ($this->bulkGoalId !== null) {
            $payload['goal_id'] = $this->bulkGoalId;
        }
        $project = Project::create($payload);
        $this->bulkProjectId = (int) $project->id;
        unset($this->bulkProjectOptions);
        $this->dispatch(
            'ss-option-added',
            model: $modelKey ?: 'bulkProjectId',
            id: $project->id,
            label: $project->name,
        );
    }
}

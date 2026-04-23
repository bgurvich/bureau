<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Models\MediaLogEntry;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Reading / watching / listening log entry. Kind = book | film |
 * show | podcast | article | game | other; status tracks the
 * wishlist → in_progress → done lifecycle. started_on fills from
 * the form when the user flips to in_progress (best-effort); same
 * for finished_on on the done flip — the goal is a clean timeline
 * without asking the user to remember exact dates.
 */
class MediaLogEntryForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasTagList;

    public ?int $id = null;

    public string $kind = 'book';

    public string $title = '';

    public string $creator = '';

    public string $status = 'wishlist';

    public string $started_on = '';

    public string $finished_on = '';

    public ?int $rating = null;

    public string $external_url = '';

    public string $notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;

        if ($id !== null) {
            $e = MediaLogEntry::findOrFail($id);
            $this->kind = (string) $e->kind;
            $this->title = (string) $e->title;
            $this->creator = (string) ($e->creator ?? '');
            $this->status = (string) $e->status;
            $this->started_on = $e->started_on ? $e->started_on->toDateString() : '';
            $this->finished_on = $e->finished_on ? $e->finished_on->toDateString() : '';
            $this->rating = $e->rating;
            $this->external_url = (string) ($e->external_url ?? '');
            $this->notes = (string) ($e->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        }
    }

    /**
     * Auto-stamp the lifecycle dates on status flips when the user
     * hasn't already filled them in. Leaves manually-entered values
     * alone so a backfilled "finished 3 months ago" keeps its date.
     */
    public function updatedStatus(string $newStatus): void
    {
        $today = now()->toDateString();

        if ($newStatus === 'in_progress' && $this->started_on === '') {
            $this->started_on = $today;
        }
        if ($newStatus === 'done' && $this->finished_on === '') {
            $this->finished_on = $today;
            if ($this->started_on === '') {
                $this->started_on = $today;
            }
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        // after_or_equal applies only when BOTH dates are set — a
        // lone historical finished_on (unknown start) is valid.
        $finishedRules = ['nullable', 'date'];
        if ($this->started_on !== '') {
            $finishedRules[] = 'after_or_equal:started_on';
        }

        $data = $this->validate([
            'kind' => ['required', Rule::in(array_keys(Enums::mediaLogKinds()))],
            'title' => 'required|string|max:255',
            'creator' => 'nullable|string|max:255',
            'status' => ['required', Rule::in(array_keys(Enums::mediaLogStatuses()))],
            'started_on' => 'nullable|date',
            'finished_on' => $finishedRules,
            'rating' => 'nullable|integer|between:1,5',
            'external_url' => 'nullable|string|max:500|url',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'kind' => $data['kind'],
            'title' => $data['title'],
            'creator' => $data['creator'] ?: null,
            'status' => $data['status'],
            'started_on' => $data['started_on'] ?: null,
            'finished_on' => $data['finished_on'] ?: null,
            'rating' => $data['rating'],
            'external_url' => $data['external_url'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            MediaLogEntry::findOrFail($this->id)->update($payload);
            $this->persistAdminOwner();
        } else {
            $payload['user_id'] = auth()->id();
            $this->id = (int) MediaLogEntry::create($payload)->id;
        }

        $this->persistTagList();

        $this->finalizeSave();
    }

    protected function adminOwnerClass(): ?string
    {
        return MediaLogEntry::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'user_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.media-log-entry-form');
    }
}

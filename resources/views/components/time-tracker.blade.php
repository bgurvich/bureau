<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\TimeTrackerSession;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?int $sessionId = null;
    public ?int $projectId = null;
    public string $description = '';
    public string $status = '';
    public int $elapsedSeconds = 0;
    public bool $showSetup = false;

    public int $quantizeMinutes = 5;

    public function mount(): void
    {
        $this->refreshSession();
    }

    protected function refreshSession(): void
    {
        $s = TimeTrackerSession::where('user_id', auth()->id())->first();
        if ($s) {
            $this->sessionId = $s->id;
            $this->projectId = $s->project_id;
            $this->description = $s->description ?? '';
            $this->status = $s->status;
            $this->elapsedSeconds = $s->elapsedSeconds();
        } else {
            $this->reset(['sessionId', 'projectId', 'description', 'status', 'elapsedSeconds']);
            $this->status = '';
            $this->elapsedSeconds = 0;
        }
    }

    #[Computed]
    public function projects()
    {
        return Project::where('archived', false)->orderBy('name')->get();
    }

    public function start(): void
    {
        if (! $this->projectId && ! trim($this->description)) {
            $this->addError('description', 'Pick a project or type a description.');
            return;
        }

        $s = TimeTrackerSession::updateOrCreate(
            ['user_id' => auth()->id()],
            [
                'project_id' => $this->projectId,
                'description' => trim($this->description) ?: null,
                'started_at' => now(),
                'paused_at' => null,
                'accumulated_seconds' => 0,
                'status' => 'running',
            ]
        );

        $this->sessionId = $s->id;
        $this->status = 'running';
        $this->elapsedSeconds = 0;
        $this->showSetup = false;
    }

    public function pause(): void
    {
        $s = TimeTrackerSession::find($this->sessionId);
        if (! $s || $s->status !== 'running') return;

        $segment = (int) $s->started_at->diffInSeconds(now(), absolute: true);
        $s->update([
            'accumulated_seconds' => $s->accumulated_seconds + $segment,
            'paused_at' => now(),
            'status' => 'paused',
        ]);
        $this->refreshSession();
    }

    public function resume(): void
    {
        $s = TimeTrackerSession::find($this->sessionId);
        if (! $s || $s->status !== 'paused') return;

        $s->update([
            'started_at' => now(),
            'paused_at' => null,
            'status' => 'running',
        ]);
        $this->refreshSession();
    }

    public function stop(): void
    {
        $s = TimeTrackerSession::find($this->sessionId);
        if (! $s) return;

        $totalSeconds = $s->elapsedSeconds();
        $increment = max(1, $this->quantizeMinutes) * 60;
        $quantized = max($increment, (int) ceil($totalSeconds / $increment) * $increment);

        TimeEntry::create([
            'household_id' => $s->household_id,
            'user_id' => $s->user_id,
            'project_id' => $s->project_id,
            'task_id' => $s->task_id,
            'started_at' => now()->subSeconds($totalSeconds),
            'ended_at' => now(),
            'duration_seconds' => $quantized,
            'activity_date' => now()->toDateString(),
            'description' => $s->description,
            'billable' => $s->project?->billable ?? false,
        ]);

        $s->delete();
        $this->refreshSession();
    }

    public function discard(): void
    {
        TimeTrackerSession::where('user_id', auth()->id())->delete();
        $this->refreshSession();
    }

    public function tick(): void
    {
        $this->refreshSession();
    }

    public function getFormattedElapsedProperty(): string
    {
        $s = max(0, $this->elapsedSeconds);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;
        return sprintf('%d:%02d:%02d', $h, $m, $sec);
    }
};
?>

<div wire:poll.5s="tick" class="relative flex items-center gap-2">
    @if ($status === 'running' || $status === 'paused')
        <div class="flex items-center gap-2 rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5">
            <span @class([
                'h-2 w-2 rounded-full',
                'bg-emerald-400 animate-pulse' => $status === 'running',
                'bg-amber-400' => $status === 'paused',
            ])></span>
            <span class="text-sm tabular-nums text-neutral-100">{{ $this->formattedElapsed }}</span>
            <span class="truncate text-xs text-neutral-500 max-w-[160px]">
                @php $p = $projectId ? $this->projects->firstWhere('id', $projectId) : null; @endphp
                {{ $p?->name ?? $description ?: '—' }}
            </span>
            @if ($status === 'running')
                <button wire:click="pause" title="Pause" class="rounded p-1 text-neutral-400 hover:bg-neutral-800 hover:text-neutral-200">⏸</button>
            @else
                <button wire:click="resume" title="Resume" class="rounded p-1 text-neutral-400 hover:bg-neutral-800 hover:text-neutral-200">▶</button>
            @endif
            <button wire:click="stop" title="Stop & log" class="rounded p-1 text-neutral-400 hover:bg-neutral-800 hover:text-neutral-200">⏹</button>
            <button wire:click="discard" title="Discard" wire:confirm="Discard this session without logging?" class="rounded p-1 text-neutral-500 hover:bg-neutral-800 hover:text-rose-400">✕</button>
        </div>
    @else
        <button wire:click="$toggle('showSetup')"
                class="rounded-md border border-neutral-800 px-3 py-1.5 text-xs text-neutral-300 hover:border-neutral-700 hover:bg-neutral-800">
            {{ $showSetup ? 'Cancel' : '+ Start timer' }}
        </button>
        @if ($showSetup)
            <form wire:submit="start" class="flex items-center gap-2">
                <select wire:model="projectId" class="rounded-md border border-neutral-700 bg-neutral-900 px-2 py-1 text-xs text-neutral-100">
                    <option value="">No project</option>
                    @foreach ($this->projects as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </select>
                <input type="text" wire:model="description" placeholder="What are you working on?"
                       class="w-56 rounded-md border border-neutral-700 bg-neutral-900 px-2 py-1 text-xs text-neutral-100 placeholder-neutral-500">
                <button type="submit" class="rounded-md bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-900 hover:bg-white">Start</button>
            </form>
            @error('description')<span class="text-xs text-rose-400">{{ $message }}</span>@enderror
        @endif
    @endif
</div>

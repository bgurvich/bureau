<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\TimeTrackerSession;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
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

    #[On('timer-open-setup')]
    public function openSetup(): void
    {
        // Only toggle setup if there is no active session — running/paused
        // timers already have their controls visible.
        if ($this->status === '' || $this->status === null) {
            $this->showSetup = true;
            $this->dispatch('timer-setup-focus');
        }
    }

    #[On('timer-stop')]
    public function stopFromShortcut(): void
    {
        if ($this->status === 'running' || $this->status === 'paused') {
            $this->stop();
        }
    }

    public ?string $startedAtIso = null;

    public int $accumulatedSeconds = 0;

    protected function refreshSession(): void
    {
        $s = TimeTrackerSession::where('user_id', auth()->id())->first();
        if ($s) {
            $this->sessionId = $s->id;
            $this->projectId = $s->project_id;
            $this->description = $s->description ?? '';
            $this->status = $s->status;
            $this->elapsedSeconds = $s->elapsedSeconds();
            $this->startedAtIso = $s->started_at?->toIso8601String();
            $this->accumulatedSeconds = (int) $s->accumulated_seconds;
        } else {
            // Intentionally preserve $projectId and $description — the user
            // might be typing into the setup form. Only clear session state.
            $this->sessionId = null;
            $this->status = '';
            $this->elapsedSeconds = 0;
            $this->startedAtIso = null;
            $this->accumulatedSeconds = 0;
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
            $this->addError('description', __('Pick a project or type a description.'));

            return;
        }

        TimeTrackerSession::updateOrCreate(
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

        // refreshSession() populates startedAtIso + accumulatedSeconds from the
        // DB row — without them the Alpine tick sees iso=null and the counter
        // never starts incrementing until the next server roundtrip.
        $this->refreshSession();
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

<div
    data-timer-status="{{ $status }}"
    x-data="{
        display: @js($this->formattedElapsed),
        interval: null,
        recompute() {
            const status = $wire.status;
            const accumulated = Number($wire.accumulatedSeconds) || 0;
            const iso = $wire.startedAtIso;
            let sec = accumulated;
            if (status === 'running' && iso) {
                sec += Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
            }
            sec = Math.max(0, sec);
            const h = Math.floor(sec / 3600);
            const m = Math.floor((sec % 3600) / 60);
            const s = sec % 60;
            this.display = `${h}:${m.toString().padStart(2,'0')}:${s.toString().padStart(2,'0')}`;
        },
        init() {
            this.recompute();
            if (this.interval === null) {
                this.interval = setInterval(() => this.recompute(), 1000);
            }
        },
    }"
    class="relative flex items-center gap-2">
    @if ($status === 'running' || $status === 'paused')
        <div class="flex items-center gap-2 rounded-lg border border-neutral-700 bg-neutral-900/60 px-2.5 py-1">
            @php $p = $projectId ? $this->projects->firstWhere('id', $projectId) : null; @endphp
            <span class="hidden max-w-[220px] truncate text-xs text-neutral-400 sm:block">
                {{ $description !== '' ? $description : ($p?->name ?? __('Untitled')) }}
            </span>
            <span x-text="display"
                  class="font-mono text-sm font-medium tabular-nums {{ $status === 'running' ? 'text-emerald-400' : 'text-amber-400' }}">{{ $this->formattedElapsed }}</span>
            <div class="flex items-center gap-1">
                @if ($status === 'running')
                    <button wire:click="pause" title="{{ __('Pause') }}" aria-label="{{ __('Pause') }}"
                            class="rounded p-1.5 text-amber-400 transition-colors hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <rect x="6" y="4" width="4" height="16"/>
                            <rect x="14" y="4" width="4" height="16"/>
                        </svg>
                    </button>
                @else
                    <button wire:click="resume" title="{{ __('Resume') }}" aria-label="{{ __('Resume') }}"
                            class="rounded p-1.5 text-emerald-400 transition-colors hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M8 5v14l11-7z"/>
                        </svg>
                    </button>
                @endif
                <button wire:click="stop" title="{{ __('Stop & log') }}" aria-label="{{ __('Stop & log') }}"
                        wire:confirm="{{ __('Stop timer and save the entry?') }}"
                        class="rounded p-1.5 text-rose-400 transition-colors hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <rect x="6" y="6" width="12" height="12" rx="1"/>
                    </svg>
                </button>
                <button wire:click="discard" title="{{ __('Discard') }}" aria-label="{{ __('Discard') }}"
                        wire:confirm="{{ __('Discard this session without logging?') }}"
                        class="rounded p-1.5 text-neutral-500 transition-colors hover:bg-neutral-800 hover:text-rose-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M6 18 18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>
    @else
        <button wire:click="$toggle('showSetup')" title="{{ __('Start timer') }}" aria-label="{{ __('Start timer') }}"
                class="flex h-8 w-8 items-center justify-center rounded-md border border-neutral-800 bg-neutral-900 text-neutral-300 transition-colors hover:border-neutral-700 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @if ($showSetup)
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M6 18 18 6M6 6l12 12"/>
                </svg>
            @else
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/>
                    <path d="M12 8v4l3 3"/>
                </svg>
            @endif
        </button>
        @if ($showSetup)
            <form wire:submit="start"
                  x-data
                  x-init="Livewire.on('timer-setup-focus', () => $nextTick(() => $refs.description?.focus()))"
                  @keydown.escape.window="$wire.set('showSetup', false)"
                  class="flex items-center gap-2">
                <select wire:model="projectId" class="rounded-md border border-neutral-700 bg-neutral-900 px-2 py-1 text-xs text-neutral-100">
                    <option value="">{{ __('No project') }}</option>
                    @foreach ($this->projects as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </select>
                <input x-ref="description"
                       x-init="$el.focus()"
                       type="text" wire:model="description" placeholder="{{ __('What are you working on?') }}"
                       class="w-56 rounded-md border border-neutral-700 bg-neutral-900 px-2 py-1 text-xs text-neutral-100 placeholder-neutral-500">
                <button type="submit" class="rounded-md bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-900 hover:bg-neutral-50">{{ __('Start') }}</button>
            </form>
            @error('description')<span class="text-xs text-rose-400">{{ $message }}</span>@enderror
        @endif
    @endif
</div>

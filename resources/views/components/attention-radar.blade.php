<?php

use App\Models\RecurringProjection;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\Transaction;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function overdueTasks(): int
    {
        return Task::where('state', 'open')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();
    }

    #[Computed]
    public function unreconciled(): int
    {
        return Transaction::where('status', 'pending')->count();
    }

    #[Computed]
    public function overdueBills(): int
    {
        return RecurringProjection::where('status', 'overdue')->count()
            + RecurringProjection::where('status', 'projected')
                ->where('due_on', '<', now()->toDateString())
                ->count();
    }

    #[Computed]
    public function pendingReminders(): int
    {
        return Reminder::where('state', 'pending')
            ->where('remind_at', '<=', now())
            ->count();
    }

    #[Computed]
    public function total(): int
    {
        return $this->overdueTasks + $this->unreconciled + $this->overdueBills + $this->pendingReminders;
    }
};
?>

<div class="rounded-xl border border-neutral-800 bg-neutral-900/50 p-5">
    <div class="mb-4 flex items-baseline justify-between">
        <h3 class="text-xs font-medium uppercase tracking-wider text-neutral-500">Attention</h3>
        <span class="text-xs tabular-nums {{ $this->total > 0 ? 'text-amber-400' : 'text-neutral-500' }}">
            {{ $this->total }} {{ $this->total === 1 ? 'item' : 'items' }}
        </span>
    </div>

    @if ($this->total === 0)
        <div class="py-6 text-center text-xs text-neutral-600">Nothing is waiting on you.</div>
    @else
        <ul class="space-y-2 text-sm">
            @if ($this->overdueTasks)
                <li class="flex items-baseline justify-between">
                    <span class="text-neutral-300">Overdue tasks</span>
                    <span class="tabular-nums text-amber-400">{{ $this->overdueTasks }}</span>
                </li>
            @endif
            @if ($this->overdueBills)
                <li class="flex items-baseline justify-between">
                    <span class="text-neutral-300">Overdue bills</span>
                    <span class="tabular-nums text-rose-400">{{ $this->overdueBills }}</span>
                </li>
            @endif
            @if ($this->unreconciled)
                <li class="flex items-baseline justify-between">
                    <span class="text-neutral-300">Unreconciled transactions</span>
                    <span class="tabular-nums text-neutral-400">{{ $this->unreconciled }}</span>
                </li>
            @endif
            @if ($this->pendingReminders)
                <li class="flex items-baseline justify-between">
                    <span class="text-neutral-300">Due reminders</span>
                    <span class="tabular-nums text-amber-400">{{ $this->pendingReminders }}</span>
                </li>
            @endif
        </ul>
    @endif
</div>

<?php

use App\Models\Account;
use App\Models\Contract;
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
        // Autopay projections only surface once >7 days past due without a match
        // (= the auto-charge actually failed). Matched projections never surface.
        $graceCutoff = now()->subDays(7)->toDateString();
        $todayStr = now()->toDateString();

        $baseCount = RecurringProjection::whereIn('status', ['overdue', 'projected'])
            ->where('autopay', false)
            ->where('due_on', '<', $todayStr)
            ->count();

        $autopayOverdue = RecurringProjection::whereIn('status', ['overdue', 'projected'])
            ->where('autopay', true)
            ->where('due_on', '<', $graceCutoff)
            ->count();

        return $baseCount + $autopayOverdue;
    }

    #[Computed]
    public function pendingReminders(): int
    {
        return Reminder::where('state', 'pending')
            ->where('remind_at', '<=', now())
            ->count();
    }

    #[Computed]
    public function trialsEndingSoon(): int
    {
        return Contract::whereNotNull('trial_ends_on')
            ->whereBetween('trial_ends_on', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->count();
    }

    #[Computed]
    public function giftCardsExpiringSoon(): int
    {
        return Account::whereIn('type', ['gift_card', 'prepaid'])
            ->whereNotNull('expires_on')
            ->whereBetween('expires_on', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->count();
    }

    #[Computed]
    public function total(): int
    {
        return $this->overdueTasks
            + $this->unreconciled
            + $this->overdueBills
            + $this->pendingReminders
            + $this->trialsEndingSoon
            + $this->giftCardsExpiringSoon;
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
            @if ($this->trialsEndingSoon)
                <li class="flex items-baseline justify-between">
                    <span class="text-neutral-300">Trials ending ≤ 7d</span>
                    <span class="tabular-nums text-rose-400">{{ $this->trialsEndingSoon }}</span>
                </li>
            @endif
            @if ($this->giftCardsExpiringSoon)
                <li class="flex items-baseline justify-between">
                    <span class="text-neutral-300">Gift cards expiring ≤ 30d</span>
                    <span class="tabular-nums text-amber-400">{{ $this->giftCardsExpiringSoon }}</span>
                </li>
            @endif
        </ul>
    @endif
</div>

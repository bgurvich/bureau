<?php

use App\Models\TimeEntry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Dashboard'])]
class extends Component
{
    #[Computed]
    public function todayHours(): float
    {
        $seconds = TimeEntry::where('user_id', auth()->id())
            ->where('activity_date', now()->toDateString())
            ->sum('duration_seconds');
        return round($seconds / 3600, 2);
    }

    #[Computed]
    public function weekHours(): float
    {
        $seconds = TimeEntry::where('user_id', auth()->id())
            ->whereBetween('activity_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])
            ->sum('duration_seconds');
        return round($seconds / 3600, 2);
    }
};
?>

<div class="space-y-6">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        <livewire:money-radar />
        <livewire:time-radar />
        <livewire:commitments-radar />
        <livewire:documents-radar />
        <livewire:attention-radar />

        <div class="rounded-xl border border-neutral-800 bg-neutral-900/50 p-5">
            <div class="mb-4 flex items-baseline justify-between">
                <h3 class="text-xs font-medium uppercase tracking-wider text-neutral-500">Time tracker</h3>
                <a href="{{ route('time.entries') }}" class="text-xs text-neutral-500 hover:text-neutral-300">All →</a>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <div class="text-xs text-neutral-500">Today</div>
                    <div class="mt-1 text-xl font-semibold tabular-nums text-neutral-100">{{ number_format($this->todayHours, 2) }}h</div>
                </div>
                <div>
                    <div class="text-xs text-neutral-500">This week</div>
                    <div class="mt-1 text-xl font-semibold tabular-nums text-neutral-100">{{ number_format($this->weekHours, 2) }}h</div>
                </div>
            </div>
            <p class="mt-4 text-xs text-neutral-500">Start a timer from the top bar; stopped timers round up to the next 5 minutes and land in your log.</p>
        </div>
    </div>
</div>

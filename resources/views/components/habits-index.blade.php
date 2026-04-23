<?php

use App\Models\ChecklistRun;
use App\Models\ChecklistTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Habits'])]
class extends Component
{
    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->habits, $this->runsToday, $this->streaks);
    }

    /** @return Collection<int, ChecklistTemplate> */
    #[Computed]
    public function habits(): Collection
    {
        /** @var Collection<int, ChecklistTemplate> $list */
        $list = ChecklistTemplate::query()
            ->where('is_habit', true)
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $list;
    }

    /**
     * Today's run keyed by template id. completed_at non-null = done.
     *
     * @return array<int, ChecklistRun>
     */
    #[Computed]
    public function runsToday(): array
    {
        $ids = $this->habits->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        return ChecklistRun::whereIn('checklist_template_id', $ids)
            ->where('run_date', CarbonImmutable::today()->toDateString())
            ->get()
            ->keyBy('checklist_template_id')
            ->all();
    }

    /**
     * Streak per template — consecutive scheduled-and-completed days
     * ending today. Computed once per render so the per-row HTML
     * doesn't trigger N queries through the relation.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function streaks(): array
    {
        $out = [];
        foreach ($this->habits as $h) {
            $out[$h->id] = $h->streak();
        }

        return $out;
    }

    /**
     * Toggle today's completion. One-click "I did it today" — doesn't
     * touch per-item ticking since habits are typically 1-item.
     */
    public function toggleToday(int $templateId): void
    {
        $template = ChecklistTemplate::find($templateId);
        if (! $template || ! $template->is_habit) {
            return;
        }

        $today = CarbonImmutable::today()->toDateString();
        $run = ChecklistRun::firstOrCreate([
            'checklist_template_id' => $templateId,
            'run_date' => $today,
        ]);

        if ($run->completed_at !== null) {
            $run->completed_at = null;
        } else {
            $run->ticked_item_ids = $template->activeItemIds();
            $run->completed_at = now();
            $run->skipped_at = null;
        }
        $run->save();

        $this->refresh();
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Habits')"
        :description="__('Daily or near-daily practices. Check off the day to extend the streak.')">
        {{-- Dispatches inspector-open with asHabit=true so the Treat-
             as-habit checkbox lands pre-checked. Inline button instead
             of new-record-button since the generic helper doesn't
             expose the extra param. --}}
        <button type="button"
                x-data
                x-on:click="Livewire.dispatch('inspector-open', { type: 'checklist_template', asHabit: true })"
                class="flex items-center gap-1 rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs font-medium text-neutral-200 hover:border-neutral-500 hover:text-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            + {{ __('New habit') }}
        </button>
    </x-ui.page-header>

    @if ($this->habits->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No habits yet. Create a checklist and mark it as "Treat as habit".') }}
        </div>
    @else
        <ul class="grid grid-cols-1 gap-3 md:grid-cols-2">
            @foreach ($this->habits as $habit)
                @php
                    $run = $this->runsToday[$habit->id] ?? null;
                    $doneToday = $run !== null && $run->completed_at !== null;
                    $scheduledToday = $habit->isScheduledOn(CarbonImmutable::today());
                    $streak = $this->streaks[$habit->id] ?? 0;
                @endphp
                <li class="flex items-center gap-3 rounded-xl border border-neutral-800 bg-neutral-900/40 p-4">
                    <button type="button"
                            wire:click="toggleToday({{ $habit->id }})"
                            aria-pressed="{{ $doneToday ? 'true' : 'false' }}"
                            aria-label="{{ $doneToday ? __('Mark :name as undone for today', ['name' => $habit->name]) : __('Mark :name as done for today', ['name' => $habit->name]) }}"
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300
                                   {{ $doneToday ? 'border-emerald-500 bg-emerald-500/20 text-emerald-400' : ($scheduledToday ? 'border-neutral-500 text-transparent hover:border-neutral-300' : 'border-neutral-800 text-transparent') }}">
                        <svg class="h-5 w-5" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                            <path d="M3 8l3.5 3.5L13 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <button type="button"
                            wire:click="$dispatch('inspector-open', { type: 'checklist_template', id: {{ $habit->id }} })"
                            class="min-w-0 flex-1 text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <div class="truncate text-sm font-medium text-neutral-100">{{ $habit->name }}</div>
                        @if ($habit->description)
                            <div class="mt-0.5 truncate text-[11px] text-neutral-500">{{ $habit->description }}</div>
                        @endif
                        <div class="mt-1 flex items-center gap-3 text-[11px] text-neutral-500">
                            @if ($streak > 0)
                                <span class="rounded bg-amber-950/40 px-1.5 py-0.5 font-mono text-amber-300"
                                      title="{{ __(':n day streak', ['n' => $streak]) }}">
                                    {{ __(':n d', ['n' => $streak]) }}
                                </span>
                            @endif
                            @if (! $scheduledToday)
                                <span>{{ __('not scheduled today') }}</span>
                            @elseif ($doneToday)
                                <span class="text-emerald-400">{{ __('done today') }}</span>
                            @else
                                <span>{{ __('pending today') }}</span>
                            @endif
                        </div>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>

<?php

use App\Models\ChecklistRun;
use App\Models\ChecklistTemplate;
use App\Support\ChecklistScheduling;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Today's rituals" — tick off checklist items against today's run of each
 * scheduled template. Supports any calendar date via ?date=YYYY-MM-DD so
 * the history grid on /life/checklists can link back here for backfills.
 *
 * Runs are created lazily on first tick / skip / done, so untouched days
 * leave zero DB footprint.
 */
new
#[Layout('components.layouts.app', ['title' => 'Rituals'])]
class extends Component
{
    #[Url(as: 'date')]
    public string $date = '';

    public function mount(): void
    {
        if ($this->date === '') {
            $this->date = now()->toDateString();
        }
    }

    public function activeDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->date)->startOfDay();
    }

    public function isToday(): bool
    {
        return $this->activeDate()->toDateString() === now()->toDateString();
    }

    /** @return Collection<int, ChecklistTemplate> */
    #[Computed]
    public function templates(): Collection
    {
        return ChecklistScheduling::templatesScheduledOn($this->activeDate());
    }

    /**
     * Existing runs for $this->date keyed by template id. Missing keys =
     * no run yet (not started).
     *
     * @return array<int, ChecklistRun>
     */
    #[Computed]
    public function runsByTemplate(): array
    {
        $ids = $this->templates->pluck('id')->all();
        if (! $ids) {
            return [];
        }

        return ChecklistRun::whereIn('checklist_template_id', $ids)
            ->where('run_date', $this->activeDate()->toDateString())
            ->get()
            ->keyBy('checklist_template_id')
            ->all();
    }

    public function toggleItem(int $templateId, int $itemId): void
    {
        $template = ChecklistTemplate::with('items')->find($templateId);
        if (! $template || ! $template->isScheduledOn($this->activeDate())) {
            return;
        }
        $activeItemIds = $template->activeItemIds();
        if (! in_array($itemId, $activeItemIds, true)) {
            return;
        }

        $run = ChecklistRun::firstOrCreate([
            'checklist_template_id' => $templateId,
            'run_date' => $this->activeDate()->toDateString(),
        ]);

        $current = $run->tickedIds();
        if (in_array($itemId, $current, true)) {
            $run->untick($itemId);
            $run->completed_at = null;
        } else {
            $run->tick($itemId);
            if ($activeItemIds !== [] && array_diff($activeItemIds, $run->tickedIds()) === []) {
                $run->completed_at = now();
            }
        }
        if ($run->skipped_at) {
            $run->skipped_at = null;
        }
        $run->save();

        unset($this->runsByTemplate);
    }

    public function markDone(int $templateId): void
    {
        $template = ChecklistTemplate::with('items')->find($templateId);
        if (! $template || ! $template->isScheduledOn($this->activeDate())) {
            return;
        }

        $run = ChecklistRun::firstOrCreate([
            'checklist_template_id' => $templateId,
            'run_date' => $this->activeDate()->toDateString(),
        ]);
        $run->ticked_item_ids = $template->activeItemIds();
        $run->completed_at = now();
        $run->skipped_at = null;
        $run->save();

        unset($this->runsByTemplate);
    }

    public function markSkipped(int $templateId): void
    {
        $template = ChecklistTemplate::find($templateId);
        if (! $template || ! $template->isScheduledOn($this->activeDate())) {
            return;
        }

        $run = ChecklistRun::firstOrCreate([
            'checklist_template_id' => $templateId,
            'run_date' => $this->activeDate()->toDateString(),
        ]);
        $run->ticked_item_ids = [];
        $run->completed_at = null;
        $run->skipped_at = now();
        $run->save();

        unset($this->runsByTemplate);
    }

    public function clearRun(int $templateId): void
    {
        ChecklistRun::where('checklist_template_id', $templateId)
            ->where('run_date', $this->activeDate()->toDateString())
            ->delete();

        unset($this->runsByTemplate);
    }

    #[On('inspector-saved')]
    public function onInspectorSaved(): void
    {
        unset($this->templates, $this->runsByTemplate);
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="$this->isToday() ? __('Today\'s rituals') : __('Rituals on :date', ['date' => $this->activeDate()->toDateString()])"
        :description="__('Tick items as you do them. Templates auto-complete when every active item is checked.')">
        <a href="{{ route('life.checklists.index') }}"
           class="text-xs text-neutral-400 underline-offset-2 hover:text-neutral-200 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            {{ __('All checklists') }} →
        </a>
    </x-ui.page-header>

    @if ($this->templates->isEmpty())
        <x-ui.empty-state>
            {{ __('No checklists scheduled for this day. Create one from the Checklists page.') }}
        </x-ui.empty-state>
    @else
        @php
            $buckets = ['morning', 'midday', 'evening', 'night', 'anytime'];
            $bucketLabels = [
                'morning' => __('Morning'),
                'midday' => __('Midday'),
                'evening' => __('Evening'),
                'night' => __('Night'),
                'anytime' => __('Anytime'),
            ];
            $grouped = $this->templates->groupBy('time_of_day');
        @endphp
        @foreach ($buckets as $bucket)
            @if (! $grouped->has($bucket))
                @continue
            @endif
            <section aria-labelledby="cl-bucket-{{ $bucket }}" class="space-y-2">
                <h3 id="cl-bucket-{{ $bucket }}" class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ $bucketLabels[$bucket] }}</h3>
                <div class="space-y-3">
                    @foreach ($grouped[$bucket] as $t)
                        @php
                            $run = $this->runsByTemplate[$t->id] ?? null;
                            $tickedRaw = $run ? array_map('intval', $run->tickedIds()) : [];
                            $activeItems = $t->items->where('active', true)->values();
                            $activeCount = $activeItems->count();
                            $doneCount = count(array_intersect($tickedRaw, $activeItems->pluck('id')->map(fn ($i) => (int) $i)->all()));
                            $isComplete = $run && $run->completed_at;
                            $isSkipped = $run && $run->skipped_at;
                        @endphp
                        <article wire:key="cl-t-{{ $t->id }}"
                                 class="rounded-xl border border-neutral-800 bg-neutral-900/50 p-4 {{ $isComplete || $isSkipped ? 'opacity-75' : '' }}">
                            <div class="flex items-baseline justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <button type="button"
                                            wire:click="$dispatch('inspector-open', { type: 'checklist_template', id: {{ $t->id }} })"
                                            class="text-left text-sm font-semibold text-neutral-100 hover:text-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                        {{ $t->name }}
                                    </button>
                                    <span class="ml-2 text-[11px] tabular-nums text-neutral-500">{{ $doneCount }}/{{ $activeCount }}</span>
                                </div>
                                <div class="flex shrink-0 items-center gap-2 text-[11px]">
                                    @if ($isSkipped)
                                        <span class="rounded border border-neutral-700 bg-neutral-950 px-2 py-0.5 text-neutral-400">{{ __('skipped') }}</span>
                                        <button type="button" wire:click="clearRun({{ $t->id }})"
                                                class="text-neutral-500 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">{{ __('undo') }}</button>
                                    @elseif ($isComplete)
                                        <span class="rounded border border-emerald-900/40 bg-emerald-900/20 px-2 py-0.5 text-emerald-300">{{ __('done') }}</span>
                                        <button type="button" wire:click="clearRun({{ $t->id }})"
                                                class="text-neutral-500 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">{{ __('undo') }}</button>
                                    @else
                                        <button type="button" wire:click="markDone({{ $t->id }})"
                                                class="rounded border border-emerald-700/40 bg-emerald-900/20 px-2 py-0.5 text-emerald-300 hover:bg-emerald-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">{{ __('Done') }}</button>
                                        <button type="button" wire:click="markSkipped({{ $t->id }})"
                                                class="rounded border border-neutral-700 px-2 py-0.5 text-neutral-400 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">{{ __('Skip') }}</button>
                                    @endif
                                </div>
                            </div>
                            @if ($activeCount === 0)
                                <p class="mt-2 text-xs text-neutral-500">{{ __('No items yet. Open the template to add some.') }}</p>
                            @else
                                <ul class="mt-3 space-y-1">
                                    @foreach ($activeItems as $item)
                                        @php $on = in_array((int) $item->id, $tickedRaw, true); @endphp
                                        <li>
                                            <button type="button"
                                                    wire:click="toggleItem({{ $t->id }}, {{ $item->id }})"
                                                    @disabled($isSkipped)
                                                    class="flex w-full items-center gap-2 rounded-md px-2 py-1 text-left text-sm {{ $on ? 'text-neutral-500 line-through' : 'text-neutral-100 hover:bg-neutral-800/40' }} focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 disabled:cursor-not-allowed">
                                                <span aria-hidden="true"
                                                      class="inline-flex h-4 w-4 items-center justify-center rounded border {{ $on ? 'border-emerald-500 bg-emerald-500/30 text-emerald-200' : 'border-neutral-700 bg-neutral-950' }}">
                                                    @if ($on)
                                                        <svg class="h-3 w-3" viewBox="0 0 12 12" fill="none">
                                                            <path d="M2 6l3 3 5-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                        </svg>
                                                    @endif
                                                </span>
                                                <span>{{ $item->label }}</span>
                                                <span class="sr-only">{{ $on ? __('Ticked') : __('Not ticked') }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif
</div>

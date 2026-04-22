<?php

use App\Models\JournalEntry;
use App\Support\Enums;
use App\Support\Formatting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Journal'])]
class extends Component
{
    /** Jump-to year (?year=2025). Defaults to showing the latest 12 months. */
    #[Url(as: 'year')]
    public ?int $yearFilter = null;

    /** Mood filter chip (?mood=good). */
    #[Url(as: 'mood')]
    public string $moodFilter = '';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->entries, $this->yearOptions);
    }

    /** @return Collection<int, JournalEntry> */
    #[Computed]
    public function entries(): Collection
    {
        /** @var Collection<int, JournalEntry> $list */
        $list = JournalEntry::query()
            ->with('user:id,name')
            ->when($this->yearFilter !== null, fn ($q) => $q->whereYear('occurred_on', $this->yearFilter))
            ->when($this->moodFilter !== '', fn ($q) => $q->where('mood', $this->moodFilter))
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();

        return $list;
    }

    /**
     * Entries bucketed by "YYYY-MM" for the month headings. Using the
     * string key keeps the render loop's sort deterministic without a
     * Carbon comparator.
     *
     * @return array<string, Collection<int, JournalEntry>>
     */
    #[Computed]
    public function entriesByMonth(): array
    {
        return $this->entries
            ->groupBy(fn (JournalEntry $e) => $e->occurred_on->format('Y-m'))
            ->all();
    }

    /**
     * Distinct years in the household's journal history — powers the
     * year-jump picker. Always includes the current year so the user
     * can navigate to it even if no entries exist there yet.
     *
     * @return array<int>
     */
    #[Computed]
    public function yearOptions(): array
    {
        $years = JournalEntry::query()
            ->selectRaw('YEAR(occurred_on) as y')
            ->distinct()
            ->orderByDesc('y')
            ->pluck('y')
            ->map(fn ($y) => (int) $y)
            ->all();

        $currentYear = (int) now()->format('Y');
        if (! in_array($currentYear, $years, true)) {
            array_unshift($years, $currentYear);
        }

        return $years;
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Journal')"
        :description="__('Daily entries — thoughts, moods, moments you want to remember.')">
        <x-ui.new-record-button type="journal_entry" :label="__('New entry')" shortcut="J" />
    </x-ui.page-header>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="jn-year" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Year') }}</label>
            <select wire:model.live="yearFilter" id="jn-year"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach ($this->yearOptions as $y)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="jn-mood" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Mood') }}</label>
            <select wire:model.live="moodFilter" id="jn-mood"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (Enums::journalMoods() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if ($this->entries->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No journal entries yet. Start with today — even one line counts.') }}
        </div>
    @else
        <div class="space-y-6">
            @foreach ($this->entriesByMonth as $monthKey => $group)
                @php
                    $heading = Carbon::createFromFormat('Y-m', $monthKey)->format('F Y');
                @endphp
                <section>
                    <h3 class="mb-2 text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ $heading }}</h3>
                    <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
                        @foreach ($group as $entry)
                            @php
                                $moodLabel = $entry->mood ? (Enums::journalMoods()[$entry->mood] ?? $entry->mood) : null;
                                $snippet = mb_strlen($entry->body) > 180
                                    ? mb_substr($entry->body, 0, 180).'…'
                                    : $entry->body;
                            @endphp
                            <x-ui.inspector-row type="journal_entry" :id="$entry->id" :label="$entry->title ?: $snippet" class="flex flex-col gap-1 px-4 py-3 text-sm">
                                <div class="flex items-baseline justify-between gap-3">
                                    <div class="flex items-baseline gap-2">
                                        <span class="w-16 shrink-0 tabular-nums text-sm text-neutral-500">{{ Formatting::date($entry->occurred_on) }}</span>
                                        @if ($entry->title)
                                            <span class="font-medium text-neutral-100">{{ $entry->title }}</span>
                                        @endif
                                    </div>
                                    <div class="flex shrink-0 items-baseline gap-2 text-sm text-neutral-500">
                                        @if ($moodLabel)
                                            <span class="rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] text-neutral-300">{{ $moodLabel }}</span>
                                        @endif
                                        @if ($entry->private)
                                            <span class="text-[10px] uppercase tracking-wider text-neutral-600">{{ __('private') }}</span>
                                        @endif
                                    </div>
                                </div>
                                <p class="text-sm leading-relaxed text-neutral-300">{{ $snippet }}</p>
                                @if ($entry->weather || $entry->location || $entry->user)
                                    <div class="mt-0.5 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                                        @if ($entry->weather)<span>{{ $entry->weather }}</span>@endif
                                        @if ($entry->location)<span>{{ $entry->location }}</span>@endif
                                        @if ($entry->user)<span>— {{ $entry->user->name }}</span>@endif
                                    </div>
                                @endif
                            </x-ui.inspector-row>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</div>

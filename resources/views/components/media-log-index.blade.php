<?php

use App\Models\MediaLogEntry;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Reading / watching'])]
class extends Component
{
    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'kind')]
    public string $kindFilter = '';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->entries, $this->statusCounts);
    }

    /** @return Collection<int, MediaLogEntry> */
    #[Computed]
    public function entries(): Collection
    {
        /** @var Collection<int, MediaLogEntry> $list */
        $list = MediaLogEntry::query()
            ->with('user:id,name')
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter))
            // Lifecycle order: in-progress first (what the user is
            // actively working on), then wishlist, then the rest by
            // finished/created recency.
            ->orderByRaw("FIELD(status, 'in_progress', 'paused', 'wishlist', 'done', 'dropped')")
            ->orderByDesc('finished_on')
            ->orderByDesc('updated_at')
            ->get();

        return $list;
    }

    /**
     * Counts per status → the chip row at the top showing at-a-glance
     * how many things are on the wishlist / in progress / done.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        return MediaLogEntry::query()
            ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter))
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Reading / watching')"
        :description="__('Personal log — books, films, podcasts, shows. Wishlist on one end, done on the other.')">
        <x-ui.new-record-button type="media_log_entry" :label="__('New entry')" />
    </x-ui.page-header>

    <div class="flex flex-wrap gap-3 text-sm">
        @foreach (\App\Support\Enums::mediaLogStatuses() as $v => $l)
            @php
                $count = $this->statusCounts[$v] ?? 0;
            @endphp
            <button type="button"
                    wire:click="$set('statusFilter', '{{ $this->statusFilter === $v ? '' : $v }}')"
                    class="rounded-md border px-2 py-1 text-xs tabular-nums focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $this->statusFilter === $v ? 'border-neutral-400 bg-neutral-800 text-neutral-100' : 'border-neutral-700 bg-neutral-900 text-neutral-400 hover:border-neutral-600 hover:text-neutral-200' }}">
                {{ $l }} <span class="ml-1 text-neutral-500">· {{ $count }}</span>
            </button>
        @endforeach
    </div>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="ml-kind" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Kind') }}</label>
            <select wire:model.live="kindFilter" id="ml-kind"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (\App\Support\Enums::mediaLogKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if ($this->entries->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('Nothing logged yet. Add something you\'re reading, watching, or want to.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->entries as $e)
                @php
                    $kindLabel = \App\Support\Enums::mediaLogKinds()[$e->kind] ?? $e->kind;
                    $statusLabel = \App\Support\Enums::mediaLogStatuses()[$e->status] ?? $e->status;
                    $statusClass = match ($e->status) {
                        'in_progress' => 'text-emerald-400',
                        'wishlist' => 'text-sky-400',
                        'done' => 'text-neutral-400',
                        'dropped' => 'text-rose-400',
                        'paused' => 'text-amber-400',
                        default => 'text-neutral-500',
                    };
                @endphp
                <x-ui.inspector-row type="media_log_entry" :id="$e->id" :label="$e->title" class="flex items-start gap-4 px-4 py-3 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-300">{{ $kindLabel }}</span>
                            <span class="truncate font-medium text-neutral-100">{{ $e->title }}</span>
                            @if ($e->creator)
                                <span class="text-neutral-500">· {{ $e->creator }}</span>
                            @endif
                        </div>
                        <div class="mt-0.5 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                            <span class="{{ $statusClass }}">{{ $statusLabel }}</span>
                            @if ($e->started_on)<span>{{ __('Started :d', ['d' => $e->started_on->toDateString()]) }}</span>@endif
                            @if ($e->finished_on)<span>{{ __('Finished :d', ['d' => $e->finished_on->toDateString()]) }}</span>@endif
                            @if ($e->user)<span>— {{ $e->user->name }}</span>@endif
                        </div>
                    </div>
                    <div class="shrink-0 text-right">
                        @if ($e->rating)
                            <div class="text-sm tabular-nums text-amber-400">{{ str_repeat('★', $e->rating) }}<span class="text-neutral-700">{{ str_repeat('★', 5 - $e->rating) }}</span></div>
                        @endif
                        @if ($e->external_url)
                            <a href="{{ $e->external_url }}" target="_blank" rel="noopener"
                               @click.stop
                               class="text-[11px] text-neutral-500 underline-offset-2 hover:text-neutral-300 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                {{ __('Link ↗') }}
                            </a>
                        @endif
                    </div>
                </x-ui.inspector-row>
            @endforeach
        </ul>
    @endif
</div>

<?php

use App\Models\Note;
use App\Support\Formatting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Notes'])]
class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'tag')]
    public string $tagFilter = '';

    public bool $pinnedOnly = false;

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->notes);
    }

    #[Computed]
    public function notes(): Collection
    {
        return Note::query()
            ->with('tags:id,name,slug')
            ->when($this->pinnedOnly, fn ($q) => $q->where('pinned', true))
            ->when($this->tagFilter !== '', fn ($q) => $q
                ->whereHas('tags', fn ($t) => $t->where('slug', $this->tagFilter))
            )
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('title', 'like', $term)
                    ->orWhere('body', 'like', $term)
                );
            })
            ->orderByDesc('pinned')
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Notes') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Quick captures, pinned first.') }}</p>
        </div>
        <x-ui.new-record-button type="note" :label="__('New note')" shortcut="N" />
    </header>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="n-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="n-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Title or body…') }}">
        </div>
        <label class="flex items-center gap-2 text-xs text-neutral-300">
            <input wire:model.live="pinnedOnly" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Pinned only') }}
        </label>
    </form>

    @if ($tagFilter !== '')
        <div role="status" class="flex items-center justify-between rounded-lg border border-emerald-800/40 bg-emerald-900/20 px-4 py-2 text-sm text-emerald-200">
            <span class="font-mono">{{ __('Filtering by') }} #{{ $tagFilter }}</span>
            <button type="button" wire:click="$set('tagFilter', '')"
                    class="rounded-md px-2 py-1 text-xs text-emerald-200 hover:bg-emerald-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ __('Clear') }}
            </button>
        </div>
    @endif

    @if ($this->notes->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No notes yet.') }}
        </div>
    @else
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->notes as $n)
                <button type="button"
                        wire:click="$dispatch('inspector-open', { type: 'note', id: {{ $n->id }} })"
                        class="flex flex-col gap-2 rounded-xl border border-neutral-800 bg-neutral-900/40 p-4 text-left transition hover:border-neutral-700 hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-sm font-medium text-neutral-100">{{ $n->title ?: __('Untitled') }}</span>
                        <span class="flex items-center gap-1 text-xs">
                            @if ($n->pinned)
                                <span aria-label="{{ __('Pinned') }}" class="text-amber-400">★</span>
                            @endif
                            @if ($n->private)
                                <span aria-label="{{ __('Private') }}" class="text-neutral-500">🔒</span>
                            @endif
                        </span>
                    </div>
                    <div class="line-clamp-5 whitespace-pre-wrap text-xs text-neutral-400">{{ $n->body }}</div>
                    <x-ui.tag-chips :tags="$n->tags" :active="$tagFilter" />
                    <div class="mt-auto text-[11px] text-neutral-500 tabular-nums">{{ Formatting::date($n->updated_at) }}</div>
                </button>
            @endforeach
        </div>
    @endif
</div>

<?php

use App\Models\Tag;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Tags'])]
class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    /**
     * Tags ordered by total taggables count descending.
     *
     * @return Collection<int, object{tag: Tag, count: int}>
     */
    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->leftJoin('taggables', 'taggables.tag_id', '=', 'tags.id')
            ->select('tags.id', 'tags.slug', 'tags.name', 'tags.household_id')
            ->selectRaw('COUNT(taggables.tag_id) as count')
            ->groupBy('tags.id', 'tags.slug', 'tags.name', 'tags.household_id')
            ->orderByDesc('count')
            ->orderBy('tags.name')
            ->limit(300)
            ->get();
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Tags') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">
                {{ __('Every tag across every domain. Click one to see everything touching that tag.') }}
            </p>
        </div>
        <div>
            <label for="tg-q" class="sr-only">{{ __('Search tags') }}</label>
            <input wire:model.live.debounce.200ms="search" id="tg-q" type="text"
                   placeholder="{{ __('Filter tags…') }}"
                   class="w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </header>

    @if ($this->tags->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No tags yet. Add tags via the Inspector on any record.') }}
        </div>
    @else
        <ul class="flex flex-wrap gap-2">
            @foreach ($this->tags as $t)
                <li>
                    <a href="{{ route('tags.show', $t->slug) }}"
                       class="inline-flex items-center gap-1.5 rounded-full border border-neutral-700 bg-neutral-900 px-3 py-1 text-sm text-neutral-200 transition hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <span>#{{ $t->slug }}</span>
                        <span class="rounded-full bg-neutral-800 px-1.5 py-0.5 text-[10px] tabular-nums text-neutral-400">{{ $t->count }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>

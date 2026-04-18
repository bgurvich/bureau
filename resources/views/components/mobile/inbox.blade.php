<?php

use App\Models\InventoryItem;
use App\Models\Media;
use App\Models\Note;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.mobile', ['title' => 'Inbox'])]
class extends Component
{
    /**
     * Merged recent captures across types, newest first. Each entry is a
     * normalized row the view can render without knowing the origin type.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function items(): Collection
    {
        $inventory = InventoryItem::query()
            ->with(['media' => fn ($q) => $q->wherePivot('role', 'photo')->orderByPivot('position')])
            ->whereNull('processed_at')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($i) => [
                'kind' => 'inventory',
                'key' => 'inv-'.$i->id,
                'id' => $i->id,
                'title' => $i->name,
                'subtitle' => __('Unprocessed inventory'),
                'created_at' => $i->created_at,
                'photo_id' => $i->media->first()?->id,
                'badge_class' => 'bg-amber-900/40 text-amber-300',
            ]);

        $media = Media::query()
            ->doesntHave('tags')
            ->whereDoesntHave('folder')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($m) => [
                'kind' => 'media',
                'key' => 'med-'.$m->id,
                'id' => $m->id,
                'title' => $m->original_name ?: basename($m->path),
                'subtitle' => $m->ocr_status === 'pending' ? __('OCR pending') : __('Untagged media'),
                'created_at' => $m->created_at,
                'photo_id' => str_starts_with((string) $m->mime, 'image/') ? $m->id : null,
                'badge_class' => $m->ocr_status === 'pending' ? 'bg-sky-900/40 text-sky-300' : 'bg-neutral-800 text-neutral-400',
            ]);

        $notes = Note::query()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($n) => [
                'kind' => 'note',
                'key' => 'note-'.$n->id,
                'id' => $n->id,
                'title' => $n->title ?: Str::limit((string) $n->body, 60),
                'subtitle' => __('Note'),
                'created_at' => $n->created_at,
                'photo_id' => null,
                'badge_class' => $n->pinned ? 'bg-amber-900/40 text-amber-300' : 'bg-neutral-800 text-neutral-400',
            ]);

        return $inventory
            ->concat($media)
            ->concat($notes)
            ->sortByDesc('created_at')
            ->values()
            ->take(40);
    }
};
?>

<div class="space-y-4">
    <header class="pt-2">
        <h1 class="text-lg font-semibold text-neutral-100">{{ __('Inbox') }}</h1>
        <p class="mt-1 text-xs text-neutral-500">{{ __('Recent captures. Tap through for context; finish on desktop.') }}</p>
    </header>

    @if ($this->items->isEmpty())
        <div class="rounded-2xl border border-dashed border-neutral-800 bg-neutral-900/40 p-8 text-center text-sm text-neutral-500">
            {{ __('Nothing here yet.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 overflow-hidden rounded-2xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->items as $it)
                <li wire:key="{{ $it['key'] }}" class="flex items-start gap-3 px-3 py-3">
                    @if ($it['photo_id'])
                        <img src="{{ route('media.file', $it['photo_id']) }}"
                             alt=""
                             loading="lazy"
                             class="h-12 w-12 shrink-0 rounded-md border border-neutral-800 bg-neutral-950 object-cover">
                    @else
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-md border border-neutral-800 bg-neutral-950 text-neutral-500">
                            @if ($it['kind'] === 'note')
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M5 4h14v16H5z"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h4"/>
                                </svg>
                            @elseif ($it['kind'] === 'inventory')
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="4" y="6" width="16" height="14" rx="2"/><path d="M9 6V4h6v2"/>
                                </svg>
                            @else
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="4" y="4" width="16" height="16" rx="2"/>
                                </svg>
                            @endif
                        </div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="shrink-0 rounded px-1.5 py-0.5 text-[9px] font-medium uppercase tracking-wider {{ $it['badge_class'] }}">
                                {{ $it['kind'] }}
                            </span>
                            <span class="truncate text-sm text-neutral-100">{{ $it['title'] }}</span>
                        </div>
                        <div class="mt-0.5 flex items-baseline justify-between gap-2 text-[11px] text-neutral-500">
                            <span class="truncate">{{ $it['subtitle'] }}</span>
                            <span class="shrink-0 tabular-nums">{{ $it['created_at']?->diffForHumans(short: true) }}</span>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>

<?php

use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->roots, $this->childrenByParent, $this->itemCounts);
    }

    /** @return Collection<int, Location> */
    #[Computed]
    public function roots(): Collection
    {
        return Location::query()
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get(['id', 'name', 'kind', 'property_id']);
    }

    /** @return array<int, Collection<int, Location>> */
    #[Computed]
    public function childrenByParent(): array
    {
        $out = [];
        foreach (Location::query()->whereNotNull('parent_id')->orderBy('name')->get(['id', 'name', 'kind', 'parent_id', 'property_id']) as $l) {
            $out[(int) $l->parent_id] = $out[(int) $l->parent_id] ?? collect();
            $out[(int) $l->parent_id]->push($l);
        }

        return $out;
    }

    /** @return array<int, int> */
    #[Computed]
    public function itemCounts(): array
    {
        return \App\Models\InventoryItem::query()
            ->selectRaw('location_id, COUNT(*) as c')
            ->whereNotNull('location_id')
            ->groupBy('location_id')
            ->pluck('c', 'location_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
};
?>

@php
    $kindBadge = static fn (string $k): string => match ($k) {
        'area' => 'bg-indigo-900/30 text-indigo-300',
        'room' => 'bg-emerald-900/30 text-emerald-300',
        'container' => 'bg-amber-900/30 text-amber-300',
        default => 'bg-neutral-800 text-neutral-400',
    };
@endphp

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-sm font-medium text-neutral-200">{{ __('Locations') }}</h2>
            <p class="mt-0.5 text-xs text-neutral-500">{{ __('House → room → shelf → box. Click to edit; + child adds a nested location.') }}</p>
        </div>
        <x-ui.new-record-button type="location" :label="__('New location')" />
    </header>

    @if ($this->roots->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No locations yet. Start with a root like "House".') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->roots as $root)
                @include('partials.locations.node', ['node' => $root, 'depth' => 0, 'children' => $this->childrenByParent, 'counts' => $this->itemCounts, 'kindBadge' => $kindBadge])
            @endforeach
        </ul>
    @endif
</div>

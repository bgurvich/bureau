<?php

use App\Models\Listing;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Listings'])]
class extends Component
{
    /** '' | draft | live | sold | expired | cancelled. Default live — the
     *  most useful everyday view. */
    #[Url(as: 'status')]
    public string $statusFilter = 'live';

    /** '' | ebay | craigslist | ... */
    #[Url(as: 'platform')]
    public string $platformFilter = '';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->listings, $this->statusCounts, $this->platformCounts);
    }

    /** @return Collection<int, Listing> */
    #[Computed]
    public function listings(): Collection
    {
        /** @var Collection<int, Listing> $list */
        $list = Listing::query()
            ->with(['user:id,name', 'inventoryItem:id,name', 'soldTo:id,display_name'])
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->platformFilter !== '', fn ($q) => $q->where('platform', $this->platformFilter))
            ->orderByRaw("FIELD(status, 'live', 'draft', 'sold', 'expired', 'cancelled')")
            ->orderByDesc('posted_on')
            ->orderByDesc('id')
            ->get();

        return $list;
    }

    /** @return array<string, int> */
    #[Computed]
    public function statusCounts(): array
    {
        return [
            'live' => Listing::where('status', 'live')->count(),
            'draft' => Listing::where('status', 'draft')->count(),
            'sold' => Listing::where('status', 'sold')->count(),
            'expired' => Listing::where('status', 'expired')->count(),
            'cancelled' => Listing::where('status', 'cancelled')->count(),
        ];
    }

    /** @return array<string, int> */
    #[Computed]
    public function platformCounts(): array
    {
        return Listing::query()
            ->where('status', 'live')
            ->selectRaw('platform, COUNT(*) as c')
            ->groupBy('platform')
            ->pluck('c', 'platform')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Listings')"
        :description="__('Stuff you\'ve put up for sale. One row per posting on any platform — eBay, Craigslist, Facebook Marketplace, etc. Auto-post adapters land later; track URL + price + status manually today.')">
        <x-ui.new-record-button type="listing" :label="__('New listing')" />
    </x-ui.page-header>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-[11px] uppercase tracking-wider text-neutral-500">{{ __('Status') }}</span>
        @foreach (\App\Support\Enums::listingStatuses() as $v => $l)
            @php
                $count = $this->statusCounts[$v] ?? 0;
                $active = $statusFilter === $v;
            @endphp
            <button type="button"
                    wire:click="$set('statusFilter', '{{ $active ? '' : $v }}')"
                    class="rounded-md border px-2 py-1 text-xs tabular-nums focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-400 bg-neutral-800 text-neutral-100' : 'border-neutral-700 bg-neutral-900 text-neutral-400 hover:border-neutral-600 hover:text-neutral-200' }}">
                {{ $l }} <span class="ml-1 text-neutral-500">· {{ $count }}</span>
            </button>
        @endforeach
    </div>

    @if (! empty($this->platformCounts))
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-[11px] uppercase tracking-wider text-neutral-500">{{ __('Live on') }}</span>
            @foreach ($this->platformCounts as $platform => $count)
                @php $active = $platformFilter === $platform; @endphp
                <button type="button"
                        wire:click="$set('platformFilter', '{{ $active ? '' : $platform }}')"
                        class="rounded-md border px-2 py-1 text-xs tabular-nums focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-400 bg-neutral-800 text-neutral-100' : 'border-neutral-700 bg-neutral-900 text-neutral-400 hover:border-neutral-600 hover:text-neutral-200' }}">
                    {{ \App\Support\Enums::inventoryListingPlatforms()[$platform] ?? $platform }} <span class="ml-1 text-neutral-500">· {{ $count }}</span>
                </button>
            @endforeach
        </div>
    @endif

    @if ($this->listings->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No listings here. Start one from the inventory item you want to sell, or add it directly.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->listings as $l)
                @php
                    $platformLabel = \App\Support\Enums::inventoryListingPlatforms()[$l->platform] ?? $l->platform;
                    $statusLabel = \App\Support\Enums::listingStatuses()[$l->status] ?? $l->status;
                    $expiringSoon = $l->status === 'live' && $l->expires_on
                        && $l->expires_on->startOfDay()->isBetween(now()->startOfDay(), now()->addDays(7)->endOfDay());
                @endphp
                <x-ui.inspector-row type="listing" :id="$l->id" :label="$l->title" class="flex items-start gap-4 px-4 py-3 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-300">{{ $platformLabel }}</span>
                            <span class="font-medium text-neutral-100 {{ $l->status === 'cancelled' ? 'line-through opacity-70' : '' }}">{{ $l->title }}</span>
                            <x-ui.row-badge :state="match ($l->status) {
                                'live' => 'active',
                                'sold' => 'achieved',
                                'draft' => 'paused',
                                'expired', 'cancelled' => 'overdue',
                                default => 'default',
                            }">{{ $statusLabel }}</x-ui.row-badge>
                            @if ($l->external_url)
                                <a href="{{ $l->external_url }}" target="_blank" rel="noopener noreferrer"
                                   class="text-[11px] text-neutral-500 underline-offset-2 hover:text-neutral-200 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                                   wire:click.stop>
                                    {{ __('open →') }}
                                </a>
                            @endif
                        </div>
                        <div class="mt-0.5 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                            @if ($l->inventoryItem)
                                <span>{{ $l->inventoryItem->name }}</span>
                            @endif
                            @if ($l->posted_on)
                                <span>{{ __('posted :d', ['d' => \App\Support\Formatting::date($l->posted_on)]) }}</span>
                            @endif
                            @if ($expiringSoon)
                                <span class="text-amber-400">{{ __('expires :d', ['d' => \App\Support\Formatting::date($l->expires_on)]) }}</span>
                            @elseif ($l->expires_on && $l->status === 'live')
                                <span>{{ __('expires :d', ['d' => \App\Support\Formatting::date($l->expires_on)]) }}</span>
                            @endif
                            @if ($l->status === 'sold' && $l->sold_for !== null)
                                <span class="text-emerald-400">{{ __('sold for :p', ['p' => \App\Support\Formatting::money((float) $l->sold_for, $l->currency ?? 'USD')]) }}</span>
                                @if ($l->soldTo)<span>— {{ $l->soldTo->display_name }}</span>@endif
                            @endif
                        </div>
                    </div>
                    <div class="shrink-0 text-right">
                        @if ($l->price !== null)
                            <div class="text-sm tabular-nums text-neutral-100">{{ \App\Support\Formatting::money((float) $l->price, $l->currency ?? 'USD') }}</div>
                        @endif
                    </div>
                </x-ui.inspector-row>
            @endforeach
        </ul>
    @endif
</div>

<?php

use App\Models\Pet;
use App\Models\PetLicense;
use App\Support\CurrentHousehold;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Pet licenses'])]
class extends Component
{
    #[Url(as: 'pet')]
    public ?int $petFilter = null;

    /** '' | 'expired' | 'expiring' (≤30d). */
    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->licenses, $this->pets);
    }

    /** @return Collection<int, PetLicense> */
    #[Computed]
    public function licenses(): Collection
    {
        /** @var Collection<int, PetLicense> $list */
        $list = PetLicense::query()
            ->with('pet:id,name')
            ->when($this->petFilter !== null, fn ($q) => $q->where('pet_id', $this->petFilter))
            ->when($this->statusFilter === 'expired', fn ($q) => $q
                ->whereNotNull('expires_on')
                ->where('expires_on', '<', now()->toDateString()))
            ->when($this->statusFilter === 'expiring', fn ($q) => $q
                ->whereNotNull('expires_on')
                ->whereBetween('expires_on', [now()->toDateString(), now()->addDays(30)->toDateString()]))
            ->orderBy('expires_on')
            ->get();

        return $list;
    }

    /** @return Collection<int, Pet> */
    #[Computed]
    public function pets(): Collection
    {
        /** @var Collection<int, Pet> $list */
        $list = Pet::orderBy('name')->get(['id', 'name']);

        return $list;
    }

    #[Computed]
    public function currency(): string
    {
        return CurrentHousehold::get()?->default_currency ?? 'USD';
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Pet licenses') }}</h2>
            <p class="mt-1 text-sm text-neutral-500">{{ __('City / county registrations. Renewals live on the Attention radar.') }}</p>
        </div>
        <div class="text-xs text-neutral-500">
            {{ __('Pick a pet below to add a license, or use the Quick-add drawer.') }}
        </div>
    </header>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="pl-pet" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Pet') }}</label>
            <select wire:model.live="petFilter" id="pl-pet"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach ($this->pets as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="pl-status" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Status') }}</label>
            <select wire:model.live="statusFilter" id="pl-status"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                <option value="expiring">{{ __('Expiring ≤ 30d') }}</option>
                <option value="expired">{{ __('Expired') }}</option>
            </select>
        </div>
    </form>

    @if ($this->licenses->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No licenses on file yet.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->licenses as $l)
                @php
                    $expDays = $l->expires_on ? (int) now()->startOfDay()->diffInDays($l->expires_on, absolute: false) : null;
                    $expClass = match (true) {
                        $expDays === null => 'text-neutral-500',
                        $expDays < 0 => 'text-rose-400',
                        $expDays <= 30 => 'text-rose-400',
                        $expDays <= 90 => 'text-amber-400',
                        default => 'text-neutral-500',
                    };
                @endphp
                <x-ui.inspector-row type="pet_license" :id="$l->id" :label="$l->authority" class="flex items-start gap-4 px-4 py-3 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="truncate font-medium text-neutral-100">{{ $l->pet?->name ?? __('(removed)') }}</span>
                            <span class="text-xs text-neutral-500">{{ $l->authority }}</span>
                            @if ($l->license_number)
                                <span class="font-mono text-[11px] text-neutral-600">#{{ $l->license_number }}</span>
                            @endif
                        </div>
                        <div class="mt-0.5 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                            @if ($l->issued_on)<span>{{ __('Issued :d', ['d' => \App\Support\Formatting::date($l->issued_on)]) }}</span>@endif
                            @if ($l->expires_on)
                                <span class="{{ $expClass }} tabular-nums">
                                    {{ __('Exp.') }} {{ \App\Support\Formatting::date($l->expires_on) }}
                                    @if ($expDays !== null)
                                        @if ($expDays < 0) · {{ __('expired') }}
                                        @else · {{ $expDays }}d @endif
                                    @endif
                                </span>
                            @endif
                        </div>
                    </div>
                    @if ($l->fee !== null)
                        <div class="shrink-0 text-right text-sm tabular-nums text-neutral-300">
                            {{ \App\Support\Formatting::money((float) $l->fee, $l->currency ?? $this->currency) }}
                        </div>
                    @endif
                </x-ui.inspector-row>
            @endforeach
        </ul>
    @endif
</div>

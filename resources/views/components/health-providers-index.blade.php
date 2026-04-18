<?php

use App\Models\HealthProvider;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Health providers'])]
class extends Component
{
    #[Url(as: 'specialty')]
    public string $specialtyFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Computed]
    public function providers(): Collection
    {
        return HealthProvider::query()
            ->with(['contact:id,display_name,phones,emails', 'appointments:id,provider_id,starts_at'])
            ->when($this->specialtyFilter !== '', fn ($q) => $q->where('specialty', $this->specialtyFilter))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where('name', 'like', $term);
            })
            ->orderBy('specialty')
            ->orderBy('name')
            ->get();
    }

    /** @return array<string, string> */
    #[Computed]
    public function specialties(): array
    {
        return \App\Support\Enums::healthProviderSpecialties();
    }
};
?>

<div class="space-y-5">
    <header>
        <h2 class="text-base font-semibold text-neutral-100">{{ __('Health providers') }}</h2>
        <p class="mt-1 text-xs text-neutral-500">{{ __('Doctors, dentists, vets, and the rest of your care team.') }}</p>
    </header>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="hp-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="hp-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Provider name…') }}">
        </div>
        <div>
            <label for="hp-spec" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Specialty') }}</label>
            <select wire:model.live="specialtyFilter" id="hp-spec"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach ($this->specialties as $k => $label)
                    <option value="{{ $k }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if ($this->providers->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No providers yet.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->providers as $hp)
                @php
                    $phone = is_array($hp->contact?->phones) ? ($hp->contact->phones[0] ?? null) : null;
                    $email = is_array($hp->contact?->emails) ? ($hp->contact->emails[0] ?? null) : null;
                    $upcoming = $hp->appointments->where('starts_at', '>=', now())->count();
                @endphp
                <li class="flex items-start justify-between gap-4 px-4 py-3 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="truncate text-neutral-100">{{ $hp->name }}</span>
                            @if ($hp->specialty)
                                <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">
                                    {{ $this->specialties[$hp->specialty] ?? $hp->specialty }}
                                </span>
                            @endif
                        </div>
                        <div class="mt-0.5 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                            @if ($phone)
                                <span class="tabular-nums">{{ $phone }}</span>
                            @endif
                            @if ($email)
                                <span>{{ $email }}</span>
                            @endif
                            @if ($upcoming > 0)
                                <span class="text-amber-400">{{ __(':n upcoming', ['n' => $upcoming]) }}</span>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>

<?php

use App\Models\Pet;
use App\Support\Birthdays;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Pets'])]
class extends Component
{
    #[Url(as: 'species')]
    public string $speciesFilter = '';

    #[Url(as: 'archived')]
    public bool $showArchived = false;

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->pets);
    }

    #[Computed]
    public function pets(): Collection
    {
        return Pet::query()
            ->with([
                'vetProvider:id,name',
                'photo:id,storage_path',
                'vaccinations' => fn ($q) => $q->whereNotNull('valid_until')->orderBy('valid_until'),
                'checkups' => fn ($q) => $q->whereNotNull('next_due_on')->orderBy('next_due_on'),
            ])
            ->when($this->speciesFilter !== '', fn ($q) => $q->where('species', $this->speciesFilter))
            ->when(! $this->showArchived, fn ($q) => $q->where('is_active', true))
            ->orderBy('species')
            ->orderBy('name')
            ->get();
    }
};
?>

<div class="space-y-5">
    {{-- The hub (pets-hub) owns the page title + description; this
         child surface just renders its own controls. --}}
    <div class="flex items-baseline justify-end">
        <x-ui.new-record-button type="pet" :label="__('New pet')" />
    </div>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="p-species" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Species') }}</label>
            <select wire:model.live="speciesFilter" id="p-species"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                <option value="dog">{{ __('Dog') }}</option>
                <option value="cat">{{ __('Cat') }}</option>
                <option value="rabbit">{{ __('Rabbit') }}</option>
                <option value="ferret">{{ __('Ferret') }}</option>
                <option value="other">{{ __('Other') }}</option>
            </select>
        </div>
        <label class="flex items-center gap-2 text-xs text-neutral-300">
            <input wire:model.live="showArchived" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Include archived') }}
        </label>
    </form>

    @if ($this->pets->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No pets yet. Add one to track vaccines, checkups, and grooming.') }}
        </div>
    @else
        @php($today = CarbonImmutable::today())
        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->pets as $p)
                @php($age = $p->date_of_birth ? Birthdays::ageOn($p->date_of_birth, $today) : null)
                @php($nextVax = $p->vaccinations->first(fn ($v) => $v->valid_until && $v->valid_until->greaterThanOrEqualTo($today)))
                @php($expiredVax = $p->vaccinations->first(fn ($v) => $v->valid_until && $v->valid_until->lessThan($today)))
                @php($nextCheckup = $p->checkups->first(fn ($c) => $c->next_due_on && $c->next_due_on->greaterThanOrEqualTo($today)))
                @php($overdueCheckup = $p->checkups->first(fn ($c) => $c->next_due_on && $c->next_due_on->lessThan($today)))
                <li>
                    <button type="button"
                            wire:click="$dispatch('inspector-open', { type: 'pet', id: {{ $p->id }} })"
                            class="w-full rounded-xl border border-neutral-800 bg-neutral-900/40 p-4 text-left transition hover:border-neutral-700 hover:bg-neutral-900/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-neutral-100">{{ $p->name }}</div>
                                <div class="mt-0.5 text-[11px] text-neutral-500">
                                    {{ ucfirst($p->species) }}@if ($p->breed) · {{ $p->breed }}@endif
                                    @if ($age !== null) · {{ __(':n yr', ['n' => $age]) }}@endif
                                </div>
                            </div>
                            @unless ($p->is_active)
                                <span class="rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ __('Archived') }}</span>
                            @endunless
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-[11px]">
                            <div>
                                <dt class="text-neutral-500">{{ __('Next vaccine') }}</dt>
                                <dd class="mt-0.5 tabular-nums {{ $expiredVax ? 'text-rose-300' : ($nextVax ? 'text-neutral-200' : 'text-neutral-600') }}">
                                    @if ($expiredVax)
                                        {{ __('overdue') }}: {{ Formatting::date($expiredVax->valid_until) }}
                                    @elseif ($nextVax)
                                        {{ Formatting::date($nextVax->valid_until) }}
                                    @else
                                        —
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt class="text-neutral-500">{{ __('Next checkup') }}</dt>
                                <dd class="mt-0.5 tabular-nums {{ $overdueCheckup ? 'text-rose-300' : ($nextCheckup ? 'text-neutral-200' : 'text-neutral-600') }}">
                                    @if ($overdueCheckup)
                                        {{ __('overdue') }}: {{ Formatting::date($overdueCheckup->next_due_on) }}
                                    @elseif ($nextCheckup)
                                        {{ Formatting::date($nextCheckup->next_due_on) }}
                                    @else
                                        —
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>

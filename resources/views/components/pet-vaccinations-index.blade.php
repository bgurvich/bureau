<?php

use App\Models\PetVaccination;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    /**
     * Filter: 'open' (default — administered rows that either expire in
     * the future or have no fixed expiry; drops already-expired rows
     * into a dedicated bucket), 'expired', 'all', 'placeholder' (seeded
     * rows still waiting for the first dose).
     */
    #[Url(as: 'state')]
    public string $stateFilter = 'open';

    #[Url(as: 'species')]
    public string $speciesFilter = '';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->rows);
    }

    #[Computed]
    public function rows(): Collection
    {
        $today = CarbonImmutable::today()->toDateString();

        $q = PetVaccination::query()
            ->with(['pet:id,name,species', 'provider:id,name']);

        // Species filter lives on the parent pet.
        if ($this->speciesFilter !== '') {
            $q->whereHas('pet', fn ($p) => $p->where('species', $this->speciesFilter));
        }

        switch ($this->stateFilter) {
            case 'placeholder':
                $q->whereNull('administered_on');
                break;
            case 'expired':
                $q->whereNotNull('valid_until')->where('valid_until', '<', $today);
                break;
            case 'open':
                $q->whereNotNull('administered_on')
                    ->where(fn ($w) => $w->whereNull('valid_until')
                        ->orWhere('valid_until', '>=', $today));
                break;
            case 'all':
            default:
                // no extra filter
                break;
        }

        return $q->orderByRaw('valid_until IS NULL, valid_until ASC')
            ->orderBy('vaccine_name')
            ->limit(500)
            ->get();
    }
};
?>

<div class="space-y-4">
    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="pv-state" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('State') }}</label>
            <select wire:model.live="stateFilter" id="pv-state"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="open">{{ __('Open (in effect)') }}</option>
                <option value="expired">{{ __('Expired') }}</option>
                <option value="placeholder">{{ __('Placeholders (not done)') }}</option>
                <option value="all">{{ __('All') }}</option>
            </select>
        </div>
        <div>
            <label for="pv-species" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Species') }}</label>
            <select wire:model.live="speciesFilter" id="pv-species"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                <option value="dog">{{ __('Dog') }}</option>
                <option value="cat">{{ __('Cat') }}</option>
                <option value="rabbit">{{ __('Rabbit') }}</option>
                <option value="ferret">{{ __('Ferret') }}</option>
                <option value="other">{{ __('Other') }}</option>
            </select>
        </div>
    </form>

    @if ($this->rows->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-8 text-center text-sm text-neutral-500">
            {{ __('Nothing to show for those filters.') }}
        </div>
    @else
        @php($today = CarbonImmutable::today())
        <div class="overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
            <table class="w-full text-sm">
                <thead class="bg-neutral-900/60 text-[10px] uppercase tracking-wider text-neutral-500">
                    <tr>
                        <th class="px-3 py-2 text-left">{{ __('Pet') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('Vaccine') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('Administered') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('Valid until') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('Booster') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800/60">
                    @foreach ($this->rows as $v)
                        @php($expired = $v->valid_until && $v->valid_until->lessThan($today))
                        @php($placeholder = $v->administered_on === null)
                        <tr wire:key="pv-{{ $v->id }}"
                            tabindex="0" role="button"
                            wire:click="$dispatch('subentity-edit-open', { type: 'pet_vaccination', id: {{ $v->id }} })"
                            @keydown.enter.prevent="$wire.dispatch('subentity-edit-open', { type: 'pet_vaccination', id: {{ $v->id }} })"
                            class="cursor-pointer transition hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <td class="px-3 py-2 text-neutral-100">
                                {{ $v->pet?->name ?? '—' }}
                                <span class="ml-1 text-[11px] text-neutral-500">{{ $v->pet?->species }}</span>
                            </td>
                            <td class="px-3 py-2">
                                {{ $v->vaccine_name }}
                                @if ($placeholder)
                                    <span class="ml-1 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ __('placeholder') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 tabular-nums text-neutral-400">{{ $v->administered_on ? Formatting::date($v->administered_on) : '—' }}</td>
                            <td class="px-3 py-2 tabular-nums {{ $expired ? 'text-rose-300' : 'text-neutral-300' }}">
                                {{ $v->valid_until ? Formatting::date($v->valid_until) : '—' }}
                                @if ($expired)
                                    <span class="ml-1 text-[10px] uppercase tracking-wider">{{ __('expired') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 tabular-nums text-neutral-400">{{ $v->booster_due_on ? Formatting::date($v->booster_due_on) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

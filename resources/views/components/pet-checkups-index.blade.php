<?php

use App\Models\PetCheckup;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url(as: 'kind')]
    public string $kindFilter = '';

    #[Url(as: 'state')]
    public string $stateFilter = 'upcoming';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->rows);
    }

    #[Computed]
    public function rows(): Collection
    {
        $today = CarbonImmutable::today()->toDateString();

        $q = PetCheckup::query()
            ->with(['pet:id,name,species', 'provider:id,name'])
            ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter));

        switch ($this->stateFilter) {
            case 'overdue':
                $q->whereNotNull('next_due_on')->where('next_due_on', '<', $today);
                break;
            case 'upcoming':
                $q->whereNotNull('next_due_on')->where('next_due_on', '>=', $today);
                break;
            case 'past':
                // Completed checkups — checkup_on is set. Sorted desc for
                // a recency-first chronicle.
                $q->whereNotNull('checkup_on');

                return $q->orderByDesc('checkup_on')->limit(500)->get();
            case 'all':
            default:
                // no extra filter
                break;
        }

        return $q->orderByRaw('next_due_on IS NULL, next_due_on ASC')
            ->limit(500)
            ->get();
    }
};
?>

<div class="space-y-4">
    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="pc-state" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('State') }}</label>
            <select wire:model.live="stateFilter" id="pc-state"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="upcoming">{{ __('Upcoming') }}</option>
                <option value="overdue">{{ __('Overdue') }}</option>
                <option value="past">{{ __('Past') }}</option>
                <option value="all">{{ __('All') }}</option>
            </select>
        </div>
        <div>
            <label for="pc-kind" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Kind') }}</label>
            <select wire:model.live="kindFilter" id="pc-kind"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                <option value="annual_checkup">{{ __('Annual checkup') }}</option>
                <option value="dental_cleaning">{{ __('Dental cleaning') }}</option>
                <option value="grooming">{{ __('Grooming') }}</option>
                <option value="blood_panel">{{ __('Blood panel') }}</option>
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
                        <th class="px-3 py-2 text-left">{{ __('Kind') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('Done') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('Next due') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('Cost') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800/60">
                    @foreach ($this->rows as $c)
                        @php($overdue = $c->next_due_on && $c->next_due_on->lessThan($today))
                        <tr wire:key="pc-{{ $c->id }}"
                            tabindex="0" role="button"
                            wire:click="$dispatch('subentity-edit-open', { type: 'pet_checkup', id: {{ $c->id }} })"
                            @keydown.enter.prevent="$wire.dispatch('subentity-edit-open', { type: 'pet_checkup', id: {{ $c->id }} })"
                            class="cursor-pointer transition hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <td class="px-3 py-2 text-neutral-100">
                                {{ $c->pet?->name ?? '—' }}
                                <span class="ml-1 text-[11px] text-neutral-500">{{ $c->pet?->species }}</span>
                            </td>
                            <td class="px-3 py-2">{{ str_replace('_', ' ', ucfirst($c->kind)) }}</td>
                            <td class="px-3 py-2 tabular-nums text-neutral-400">{{ $c->checkup_on ? Formatting::date($c->checkup_on) : '—' }}</td>
                            <td class="px-3 py-2 tabular-nums {{ $overdue ? 'text-rose-300' : 'text-neutral-300' }}">
                                {{ $c->next_due_on ? Formatting::date($c->next_due_on) : '—' }}
                                @if ($overdue)
                                    <span class="ml-1 text-[10px] uppercase tracking-wider">{{ __('overdue') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 tabular-nums text-neutral-400">{{ $c->cost !== null ? Formatting::money((float) $c->cost, $c->currency ?? 'USD') : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<?php

use App\Models\Pet;
use App\Models\PetCheckup;
use App\Models\PetVaccination;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function petCount(): int
    {
        return Pet::where('is_active', true)->count();
    }

    /**
     * Vaccinations that are expired OR within the next 30 days (actionable
     * window — vet booking needs runway). Placeholder rows (no
     * administered_on) are excluded since they aren't real yet.
     */
    #[Computed]
    public function vaccinationsDueSoon(): int
    {
        return PetVaccination::query()
            ->whereNotNull('administered_on')
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', now()->addDays(30)->toDateString())
            ->count();
    }

    /** Checkups whose next_due_on has already passed. */
    #[Computed]
    public function checkupsOverdue(): int
    {
        return PetCheckup::query()
            ->whereNotNull('next_due_on')
            ->where('next_due_on', '<', now()->toDateString())
            ->count();
    }

    /**
     * Top 3 upcoming-or-overdue per-pet events sorted by urgency. Pulls
     * the same rows the detail pages surface so the radar always aligns
     * with drill-down numbers.
     *
     * @return Collection<int, array{pet: string, label: string, when: \Carbon\Carbon, overdue: bool}>
     */
    #[Computed]
    public function upcoming(): Collection
    {
        $horizon = now()->addDays(30)->toDateString();

        $vaccines = PetVaccination::query()
            ->with('pet:id,name')
            ->whereNotNull('administered_on')
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', $horizon)
            ->orderBy('valid_until')
            ->limit(6)
            ->get(['id', 'pet_id', 'vaccine_name', 'valid_until'])
            ->map(fn (PetVaccination $v) => [
                'pet' => (string) ($v->pet?->name ?? '—'),
                'label' => $v->vaccine_name,
                'when' => $v->valid_until,
                'overdue' => $v->valid_until->lessThan(now()->startOfDay()),
            ]);

        $checkups = PetCheckup::query()
            ->with('pet:id,name')
            ->whereNotNull('next_due_on')
            ->where('next_due_on', '<=', $horizon)
            ->orderBy('next_due_on')
            ->limit(6)
            ->get(['id', 'pet_id', 'kind', 'next_due_on'])
            ->map(fn (PetCheckup $c) => [
                'pet' => (string) ($c->pet?->name ?? '—'),
                'label' => ucfirst(str_replace('_', ' ', (string) $c->kind)),
                'when' => $c->next_due_on,
                'overdue' => $c->next_due_on->lessThan(now()->startOfDay()),
            ]);

        return $vaccines
            ->concat($checkups)
            ->sortBy(fn ($e) => $e['when']->getTimestamp())
            ->take(4)
            ->values();
    }
};
?>

<div class="rounded-xl border border-neutral-800 bg-neutral-900/50 p-5">
    <div class="mb-4 flex items-baseline justify-between">
        <h3 class="text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __('Pet care') }}</h3>
        <a href="{{ route('pets.index') }}" class="text-xs text-neutral-500 hover:text-neutral-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">{{ __('All →') }}</a>
    </div>

    @if ($this->petCount === 0)
        <p class="py-6 text-center text-xs text-neutral-600">{{ __('No active pets.') }}</p>
    @else
        <div class="grid grid-cols-3 gap-4">
            <div>
                <div class="text-xs text-neutral-500">{{ __('On roster') }}</div>
                <div class="mt-1 text-xl font-semibold tabular-nums text-neutral-100">{{ $this->petCount }}</div>
            </div>
            <div>
                <div class="text-xs text-neutral-500">{{ __('Vaccines ≤ 30d') }}</div>
                <div class="mt-1 text-xl font-semibold tabular-nums {{ $this->vaccinationsDueSoon > 0 ? 'text-amber-400' : 'text-neutral-400' }}">{{ $this->vaccinationsDueSoon }}</div>
            </div>
            <div>
                <div class="text-xs text-neutral-500">{{ __('Overdue checkups') }}</div>
                <div class="mt-1 text-xl font-semibold tabular-nums {{ $this->checkupsOverdue > 0 ? 'text-rose-400' : 'text-neutral-400' }}">{{ $this->checkupsOverdue }}</div>
            </div>
        </div>

        @if ($this->upcoming->isNotEmpty())
            <ul class="mt-4 space-y-1.5 border-t border-neutral-800 pt-3 text-sm">
                @foreach ($this->upcoming as $event)
                    <li class="flex items-baseline justify-between gap-3">
                        <div class="flex min-w-0 items-baseline gap-2">
                            <span class="shrink-0 text-xs uppercase tracking-wider {{ $event['overdue'] ? 'text-rose-400' : 'text-sky-400' }}">
                                {{ $event['pet'] }}
                            </span>
                            <span class="truncate text-neutral-200">{{ $event['label'] }}</span>
                        </div>
                        <span class="shrink-0 text-xs tabular-nums {{ $event['overdue'] ? 'text-rose-300' : 'text-neutral-500' }}">
                            {{ $event['when']->diffForHumans(['parts' => 1, 'short' => true]) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>

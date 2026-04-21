<?php

use App\Models\Contract;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Insurance'])]
class extends Component
{
    #[Url(as: 'coverage')]
    public string $coverageFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[On('inspector-saved')]
    public function refresh(string $type = ''): void
    {
        if (in_array($type, ['insurance', 'contract'], true)) {
            unset($this->policies);
            unset($this->premiumBurn);
        }
    }

    #[Computed]
    public function policies(): Collection
    {
        return Contract::query()
            ->where('kind', 'insurance')
            ->with(['insurancePolicy', 'insurancePolicy.carrier:id,display_name', 'contacts:id,display_name'])
            ->when($this->coverageFilter !== '', fn ($q) => $q->whereHas('insurancePolicy',
                fn ($inner) => $inner->where('coverage_kind', $this->coverageFilter)
            ))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('title', 'like', $term)
                    ->orWhereHas('insurancePolicy',
                        fn ($ip) => $ip->where('policy_number', 'like', $term)
                    )
                );
            })
            ->orderByRaw('ends_on IS NULL, ends_on')
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function premiumBurn(): float
    {
        $monthly = 0.0;
        foreach ($this->policies as $p) {
            if (! $p->insurancePolicy || $p->insurancePolicy->premium_amount === null) {
                continue;
            }
            $amount = (float) $p->insurancePolicy->premium_amount;
            $divisor = Enums::cadenceToMonthlyDivisor((string) $p->insurancePolicy->premium_cadence);
            $monthly += $divisor !== null ? $amount / $divisor : 0.0;
        }

        return $monthly;
    }

    #[Computed]
    public function coverageKinds(): array
    {
        return Enums::insuranceCoverageKinds();
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
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Insurance') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Policies, carriers, coverage, and premiums.') }}</p>
        </div>
        <x-ui.new-record-button type="insurance" :label="__('New policy')" shortcut="S" />
    </header>

    <dl class="flex gap-5 text-xs">
        <div>
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Monthly premium') }}</dt>
            <dd class="mt-0.5 tabular-nums text-rose-400">{{ Formatting::money($this->premiumBurn, $this->currency) }}</dd>
        </div>
        <div>
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Policies') }}</dt>
            <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->policies->count() }}</dd>
        </div>
    </dl>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="ins-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="ins-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Title or policy #…') }}">
        </div>
        <div>
            <label for="ins-cov" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Coverage') }}</label>
            <select wire:model.live="coverageFilter" id="ins-cov"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach ($this->coverageKinds as $k => $label)
                    <option value="{{ $k }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if ($this->policies->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No policies match those filters.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->policies as $c)
                @php
                    $policy = $c->insurancePolicy;
                    $endsOn = $c->ends_on ? CarbonImmutable::parse($c->ends_on) : null;
                    $daysLeft = $endsOn ? (int) now()->startOfDay()->diffInDays($endsOn, absolute: false) : null;
                    $expiryClass = match (true) {
                        $daysLeft !== null && $daysLeft < 0 => 'text-rose-400',
                        $daysLeft !== null && $daysLeft <= 30 => 'text-rose-400',
                        $daysLeft !== null && $daysLeft <= 90 => 'text-amber-400',
                        default => 'text-neutral-500',
                    };
                @endphp
                <li class="cursor-pointer px-4 py-3 text-sm hover:bg-neutral-900/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                    tabindex="0"
                    role="button"
                    aria-label="{{ __('Edit :title', ['title' => $c->title]) }}"
                    wire:click="$dispatch('inspector-open', { type: 'insurance', id: {{ $c->id }} })"
                    wire:key="ins-{{ $c->id }}"
                    @keydown.enter.prevent="$wire.dispatch('inspector-open', { type: 'insurance', id: {{ $c->id }} })">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2">
                                <span class="truncate text-neutral-100">{{ $c->title }}</span>
                                @if ($policy?->coverage_kind)
                                    <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">
                                        {{ $this->coverageKinds[$policy->coverage_kind] ?? $policy->coverage_kind }}
                                    </span>
                                @endif
                                @if ($c->auto_renews)
                                    <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400" aria-label="{{ __('Auto-renews') }}">↻</span>
                                @endif
                            </div>
                            <div class="mt-0.5 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                                @if ($policy?->policy_number)
                                    <span class="tabular-nums">#{{ $policy->policy_number }}</span>
                                @endif
                                @if ($policy?->carrier)
                                    <span>{{ $policy->carrier->display_name }}</span>
                                @endif
                                @if ($endsOn)
                                    <span class="{{ $expiryClass }}">
                                        {{ __('Ends') }} {{ Formatting::date($endsOn) }}
                                        @if ($daysLeft < 0)
                                            · {{ __('expired') }}
                                        @else
                                            · {{ $daysLeft }}d
                                        @endif
                                    </span>
                                @endif
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            @if ($policy?->premium_amount !== null)
                                <div class="text-sm tabular-nums text-neutral-100">
                                    {{ Formatting::money((float) $policy->premium_amount, $policy->premium_currency ?? $this->currency) }}
                                </div>
                                <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ $policy->premium_cadence }}</div>
                            @endif
                        </div>
                    </div>
                    @if ($policy && ($policy->coverage_amount || $policy->deductible_amount))
                        <dl class="mt-2 flex flex-wrap gap-4 border-t border-neutral-800/50 pt-2 text-[11px]">
                            @if ($policy->coverage_amount)
                                <div>
                                    <dt class="text-neutral-500">{{ __('Coverage') }}</dt>
                                    <dd class="tabular-nums text-neutral-200">
                                        {{ Formatting::money((float) $policy->coverage_amount, $policy->coverage_currency ?? $this->currency) }}
                                    </dd>
                                </div>
                            @endif
                            @if ($policy->deductible_amount)
                                <div>
                                    <dt class="text-neutral-500">{{ __('Deductible') }}</dt>
                                    <dd class="tabular-nums text-neutral-200">
                                        {{ Formatting::money((float) $policy->deductible_amount, $policy->deductible_currency ?? $this->currency) }}
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>

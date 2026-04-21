<?php

use App\Models\Subscription;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /** Toggles whether cancelled rows are visible. URL-persisted so refresh doesn't lose it. */
    #[\Livewire\Attributes\Url(as: 'cancelled')]
    public bool $showCancelled = false;

    #[\Livewire\Attributes\Url(as: 'sort')]
    public string $sortBy = 'name';

    #[\Livewire\Attributes\Url(as: 'dir')]
    public string $sortDir = 'asc';

    public function sort(string $column): void
    {
        if (! in_array($column, ['name', 'monthly', 'amount'], true)) {
            return;
        }
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = $column === 'name' ? 'asc' : 'desc';
        }
    }

    #[Computed]
    public function subscriptions()
    {
        // Active + paused always visible; cancelled surfaces when toggled
        // on so the user can un-cancel via the Inspector. Paused rows
        // render dimmed so they're distinguishable from active at a glance.
        // Monthly/Annual totals below count ACTIVE only — paused + cancelled
        // spend is "not currently happening".
        $states = $this->showCancelled ? ['active', 'paused', 'cancelled'] : ['active', 'paused'];

        $query = Subscription::with([
                'recurringRule:id,title,amount,currency,rrule',
                'contract:id,title,cancellation_url,cancellation_email,ends_on,auto_renews',
                'counterparty:id,display_name',
            ])
            ->whereIn('state', $states)
            ->orderByRaw("FIELD(state, 'active', 'paused', 'cancelled')");

        // Map logical sort keys to concrete columns. 'amount' sorts by the
        // underlying rule's amount via a subquery so the list order matches
        // what the user sees in the Amount column.
        match ($this->sortBy) {
            'monthly' => $query->orderBy('monthly_cost_cached', $this->sortDir),
            'amount' => $query->orderBy(
                \App\Models\RecurringRule::select('amount')->whereColumn('recurring_rules.id', 'subscriptions.recurring_rule_id'),
                $this->sortDir,
            ),
            default => $query->orderBy('name', $this->sortDir),
        };

        return $query->get();
    }

    #[Computed]
    public function cancelledCount(): int
    {
        return Subscription::where('state', 'cancelled')->count();
    }

    #[On('inspector-saved')]
    public function onInspectorSaved(): void
    {
        unset($this->subscriptions);
    }

    /**
     * Quick state toggles. Pausing a subscription doesn't touch the
     * underlying recurring rule — use the bill Inspector to actually stop
     * the cashflow. Pause is about "hide from the radar" / "not actively
     * tracking this one right now".
     */
    public function setState(int $id, string $state): void
    {
        if (! in_array($state, ['active', 'paused', 'cancelled'], true)) {
            return;
        }
        \App\Models\Subscription::where('id', $id)->update(['state' => $state]);
        unset($this->subscriptions);
    }

    #[Computed]
    public function monthlyTotal(): ?float
    {
        // Paused subscriptions don't contribute to the monthly total — the
        // list shows them but the summary reflects what's actually being
        // billed. Cancelled rows are already excluded from $subscriptions.
        $rows = $this->subscriptions->where('state', 'active');
        $sum = 0.0;
        foreach ($rows as $s) {
            if ($s->monthly_cost_cached === null) {
                // Any row with unknown cadence voids the total — better a dash than a lie.
                return null;
            }
            $sum += (float) $s->monthly_cost_cached;
        }

        return $sum;
    }

    #[Computed]
    public function annualTotal(): ?float
    {
        return $this->monthlyTotal !== null ? $this->monthlyTotal * 12 : null;
    }

    public function with(): array
    {
        return [
            'currency' => CurrentHousehold::get()?->default_currency ?? 'USD',
        ];
    }
}; ?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Subscriptions')"
        :description="__('Auto-created from active recurring outflows. Link a contract to surface cancellation affordance.')">
        <div class="text-right text-xs">
            <div class="font-mono tabular-nums text-neutral-100">
                @if ($this->monthlyTotal !== null)
                    {{ Formatting::money($this->monthlyTotal, $currency) }}/mo
                @else
                    —
                @endif
            </div>
            <div class="font-mono tabular-nums text-neutral-500">
                @if ($this->annualTotal !== null)
                    {{ Formatting::money($this->annualTotal, $currency) }}/yr
                @endif
            </div>
        </div>
        @if ($this->cancelledCount > 0)
            <label class="flex items-center gap-1.5 text-xs text-neutral-500">
                <input type="checkbox" wire:model.live="showCancelled"
                       class="rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <span>{{ __('Show cancelled (:n)', ['n' => $this->cancelledCount]) }}</span>
            </label>
        @endif
        <button type="button"
                wire:click="$dispatch('inspector-open', { type: 'subscription' })"
                class="rounded-md bg-neutral-100 px-3 py-2 text-sm font-medium text-neutral-900 hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            {{ __('New subscription') }}
        </button>
    </x-ui.page-header>

    @if ($this->subscriptions->isEmpty())
        <x-ui.empty-state>
            {{ __('No active subscriptions. They show up here automatically when a recurring outflow rule is created.') }}
        </x-ui.empty-state>
    @else
        <x-ui.data-table>
            <thead class="border-b border-neutral-800 bg-neutral-900/60">
                <tr>
                    <x-ui.sortable-header column="name" :label="__('Subscription')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                    <x-ui.sortable-header column="amount" :label="__('Amount')" :sort-by="$sortBy" :sort-dir="$sortDir" align="right" />
                    <x-ui.sortable-header column="monthly" :label="__('Monthly')" :sort-by="$sortBy" :sort-dir="$sortDir" align="right" />
                    <th class="px-3 py-2 text-left text-[10px] uppercase tracking-wider font-medium text-neutral-500">{{ __('Cancel') }}</th>
                    <th class="px-3 py-2 text-right font-medium sr-only">{{ __('Actions') }}</th>
                </tr>
            </thead>
                <tbody class="divide-y divide-neutral-800">
                    @foreach ($this->subscriptions as $s)
                        <tr wire:key="sub-{{ $s->id }}"
                            class="cursor-pointer hover:bg-neutral-800/30 {{ $s->state !== 'active' ? 'opacity-60' : '' }}"
                            wire:click="$dispatch('inspector-open', { type: 'subscription', id: {{ $s->id }} })">
                            <td class="px-3 py-2 text-neutral-100">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium {{ $s->state === 'cancelled' ? 'line-through' : '' }}">{{ $s->name }}</span>
                                    @if ($s->state === 'paused')
                                        <x-ui.row-badge state="paused">{{ __('paused') }}</x-ui.row-badge>
                                    @elseif ($s->state === 'cancelled')
                                        <x-ui.row-badge state="cancelled">{{ __('cancelled') }}</x-ui.row-badge>
                                    @endif
                                </div>
                                @if ($s->counterparty)
                                    <div class="text-[11px] text-neutral-500">{{ $s->counterparty->display_name }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums text-neutral-200">
                                @if ($s->recurringRule)
                                    {{ Formatting::money(abs((float) $s->recurringRule->amount), $s->recurringRule->currency ?? $s->currency ?? $currency) }}
                                @else
                                    <span class="text-neutral-600">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums text-neutral-200">
                                @if ($s->monthly_cost_cached !== null)
                                    {{ Formatting::money((float) $s->monthly_cost_cached, $s->currency ?? $currency) }}
                                @else
                                    <span class="text-amber-400" title="{{ __('Cadence not recognized') }}">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-[11px]">
                                @if ($s->contract?->cancellation_url)
                                    <a href="{{ $s->contract->cancellation_url }}" target="_blank" rel="noopener noreferrer"
                                       wire:click.stop
                                       class="text-sky-300 underline-offset-2 hover:underline">{{ __('Link') }}</a>
                                @elseif ($s->contract?->cancellation_email)
                                    <a href="mailto:{{ $s->contract->cancellation_email }}"
                                       wire:click.stop
                                       class="text-sky-300 underline-offset-2 hover:underline">{{ __('Email') }}</a>
                                @else
                                    <span class="text-neutral-600">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right text-[11px]">
                                @if ($s->state === 'cancelled')
                                    <button type="button"
                                            wire:click.stop="setState({{ $s->id }}, 'active')"
                                            class="text-emerald-300 hover:text-emerald-200">{{ __('Reactivate') }}</button>
                                @elseif ($s->state === 'paused')
                                    <button type="button"
                                            wire:click.stop="setState({{ $s->id }}, 'active')"
                                            class="mr-2 text-emerald-300 hover:text-emerald-200">{{ __('Resume') }}</button>
                                    <button type="button"
                                            wire:click.stop="setState({{ $s->id }}, 'cancelled')"
                                            wire:confirm="{{ __('Mark :n cancelled?', ['n' => $s->name]) }}"
                                            class="text-rose-400 hover:text-rose-300">{{ __('Cancel') }}</button>
                                @else
                                    <button type="button"
                                            wire:click.stop="setState({{ $s->id }}, 'paused')"
                                            class="mr-2 text-neutral-500 hover:text-neutral-200">{{ __('Pause') }}</button>
                                    <button type="button"
                                            wire:click.stop="setState({{ $s->id }}, 'cancelled')"
                                            wire:confirm="{{ __('Mark :n cancelled? The underlying recurring rule stays active — deactivate it separately from the bill inspector.', ['n' => $s->name]) }}"
                                            class="text-rose-400 hover:text-rose-300">{{ __('Cancel') }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
        </x-ui.data-table>
    @endif
</div>

<?php

use App\Models\RecurringDiscovery;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use App\Support\RecurringPatternDiscovery;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Embedded card listing pending RecurringDiscovery proposals. User can
 * accept (→ opens Inspector Bill form prefilled from the proposal) or
 * dismiss (stays dismissed across reruns). "Scan now" triggers the same
 * discovery service the scheduler uses.
 */
new class extends Component
{
    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->pending);
    }

    public function scanNow(): void
    {
        $household = \App\Support\CurrentHousehold::get();
        if (! $household) {
            return;
        }
        app(RecurringPatternDiscovery::class)->discover($household);
        $this->refresh();
    }

    public function dismiss(int $id): void
    {
        RecurringDiscovery::whereKey($id)->update(['status' => 'dismissed']);
        $this->refresh();
    }

    public function dismissAll(): int
    {
        $n = RecurringDiscovery::where('status', 'pending')->update(['status' => 'dismissed']);
        $this->refresh();

        return $n;
    }

    public function accept(int $id): void
    {
        $d = RecurringDiscovery::find($id);
        if (! $d) {
            return;
        }
        $d->forceFill(['status' => 'accepted'])->save();
        $this->refresh();

        // Opens Inspector Bill form as a review step. For one-click acceptance
        // (skip review, create rule + subscription immediately) use the
        // accept-as-subscription button instead.
        $this->dispatch('inspector-open', type: 'bill', id: null);
        $this->dispatch('discovery-accepted-for-inspector',
            title: (string) ($d->counterparty?->display_name ?? $d->description_fingerprint),
            amount: (float) $d->median_amount,
            cadence: $d->cadence,
        );
    }

    /**
     * One-click: materialize a RecurringRule directly from the discovery,
     * skipping the Inspector review. SubscriptionSync's observer then auto-
     * creates the Subscription. Use when the proposal is obviously right
     * (high score, known counterparty) and review adds no value.
     */
    public function acceptAsSubscription(int $id): void
    {
        $d = RecurringDiscovery::find($id);
        if (! $d || ! $d->account_id) {
            return;
        }

        $rrule = match ($d->cadence) {
            'weekly' => 'FREQ=WEEKLY;INTERVAL=1',
            'biweekly' => 'FREQ=WEEKLY;INTERVAL=2',
            'monthly' => 'FREQ=MONTHLY;INTERVAL=1',
            'bimonthly' => 'FREQ=MONTHLY;INTERVAL=2',
            'quarterly' => 'FREQ=MONTHLY;INTERVAL=3',
            'yearly' => 'FREQ=YEARLY;INTERVAL=1',
            default => 'FREQ=MONTHLY;INTERVAL=1',
        };

        // Discoveries surface BOTH inflows (salary, refunds) and outflows
        // (subscriptions, bills). Preserve the sign from median_amount and
        // derive kind from it — forcing everything negative-expense turned
        // income rules into "expense with negative amount", breaking
        // projections and the Subscription sign convention.
        $amount = (float) $d->median_amount;
        $kind = $amount > 0 ? 'income' : 'bill';

        \App\Models\RecurringRule::create([
            'title' => (string) ($d->counterparty?->display_name ?? $d->description_fingerprint),
            'kind' => $kind,
            'amount' => $amount,
            'currency' => 'USD',
            'rrule' => $rrule,
            'dtstart' => $d->last_seen_on ?? now(),
            'active' => true,
            'account_id' => $d->account_id,
            'counterparty_contact_id' => $d->counterparty_contact_id,
        ]);

        $d->forceFill(['status' => 'accepted'])->save();
        $this->refresh();
    }

    /**
     * @return Collection<int, RecurringDiscovery>
     */
    #[Computed]
    public function pending(): Collection
    {
        return RecurringDiscovery::with('counterparty:id,display_name', 'account:id,name')
            ->where('status', 'pending')
            ->orderByDesc('score')
            ->limit(25)
            ->get();
    }
};
?>

<section aria-labelledby="discoveries-heading" class="space-y-3">
    <header class="flex items-baseline justify-between gap-3">
        <h3 id="discoveries-heading" class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Recurring patterns') }}</h3>
        <div class="flex items-center gap-2">
            @if ($this->pending->isNotEmpty())
                <button type="button" wire:click="dismissAll"
                        wire:confirm="{{ __('Dismiss all :n pending patterns? You can\'t undo; they won\'t be suggested again.', ['n' => $this->pending->count()]) }}"
                        class="rounded-md px-2 py-1 text-[11px] text-neutral-500 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Dismiss all') }}
                </button>
            @endif
            <button type="button" wire:click="scanNow"
                    class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1 text-[11px] text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <span wire:loading.remove wire:target="scanNow">{{ __('Scan now') }}</span>
                <span wire:loading wire:target="scanNow">{{ __('Scanning…') }}</span>
            </button>
        </div>
    </header>

    @if ($this->pending->isEmpty())
        <div class="rounded-lg border border-dashed border-neutral-800 bg-neutral-900/40 p-4 text-center text-xs text-neutral-500">
            {{ __('No new patterns detected. Run "Scan now" after importing statements or Plaid data.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-lg border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->pending as $d)
                <li wire:key="disc-{{ $d->id }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-neutral-100">
                            {{ $d->counterparty?->display_name ?: $d->description_fingerprint }}
                            <span class="ml-1 text-[11px] text-neutral-500">· {{ $d->cadence }}</span>
                        </div>
                        <div class="text-[11px] text-neutral-500">
                            {{ __(':n occurrences since :from', ['n' => $d->occurrence_count, 'from' => $d->first_seen_on->toDateString()]) }}
                            @if ($d->account) · {{ $d->account->name }} @endif
                        </div>
                    </div>
                    <div class="text-right text-xs tabular-nums text-neutral-200">{{ Formatting::money((float) $d->median_amount, CurrentHousehold::get()?->default_currency ?? 'USD') }}</div>
                    <div class="flex items-center gap-2">
                        @if ((float) $d->median_amount < 0 || $d->counterparty)
                            <button type="button" wire:click="acceptAsSubscription({{ $d->id }})"
                                    title="{{ __('Creates a recurring rule; the matching subscription is auto-created.') }}"
                                    class="rounded-md bg-emerald-600 px-2.5 py-1 text-[11px] font-medium text-white hover:bg-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                {{ __('Accept') }}
                            </button>
                        @endif
                        <button type="button" wire:click="accept({{ $d->id }})"
                                class="rounded-md border border-neutral-700 px-2.5 py-1 text-[11px] text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Review') }}
                        </button>
                        <button type="button" wire:click="dismiss({{ $d->id }})"
                                class="rounded-md px-2.5 py-1 text-[11px] text-neutral-400 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Dismiss') }}
                        </button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>

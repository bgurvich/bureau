<?php

use App\Models\Account;
use App\Models\AssetValuation;
use App\Models\Contract;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\Property;
use App\Models\RecurringProjection;
use App\Models\Snapshot;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AccountBalances;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Finance overview'])]
class extends Component
{
    #[Computed]
    public function currency(): string
    {
        return CurrentHousehold::get()?->default_currency ?? 'USD';
    }

    /**
     * Active in-net-worth accounts. Cached for the request so the
     * multiple callers (netWorth, netWorthByUser) share one fetch.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Account>
     */
    #[Computed]
    public function netWorthAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Account::where('is_active', true)->where('include_in_net_worth', true)->get();
    }

    /**
     * Per-account balances for the active net-worth set — computed once,
     * shared with every caller that needs per-account totals.
     *
     * @return array<int, float>
     */
    #[Computed]
    public function netWorthBalances(): array
    {
        return AccountBalances::forAccounts($this->netWorthAccounts);
    }

    /**
     * Household-wide net worth by kind (all users aggregated — this is the
     * family-level view, distinct from the Money radar's current-user view).
     *
     * @return array{total: float, accounts: float, assets: float, by_kind: array<string, float>, sparkline: array<int, array{taken_on: string, total: float}>}
     */
    #[Computed]
    public function netWorth(): array
    {
        $byKind = [
            'checking' => 0.0, 'savings' => 0.0, 'credit' => 0.0, 'cash' => 0.0, 'investment' => 0.0,
            'loan' => 0.0, 'mortgage' => 0.0, 'gift_card' => 0.0, 'prepaid' => 0.0,
            'property' => 0.0, 'vehicle' => 0.0, 'inventory' => 0.0,
        ];

        $accountsTotal = 0.0;
        $balances = $this->netWorthBalances;
        foreach ($this->netWorthAccounts as $a) {
            $bal = $balances[$a->id] ?? 0.0;
            $byKind[$a->type] = ($byKind[$a->type] ?? 0) + $bal;
            $accountsTotal += $bal;
        }

        $assetsTotal = 0.0;
        foreach ([[Property::class, 'property', 'purchase_price'], [Vehicle::class, 'vehicle', 'purchase_price'], [InventoryItem::class, 'inventory', 'cost_amount']] as [$class, $bucket, $fallback]) {
            $sum = 0.0;
            $assets = $class::query()->get();
            if ($assets->isNotEmpty()) {
                // Pull every asset's newest valuation in one query instead
                // of a per-asset lookup. unique('valuable_id') on the
                // desc-sorted set keeps the most recent row per asset.
                $latest = AssetValuation::where('valuable_type', $class)
                    ->whereIn('valuable_id', $assets->pluck('id'))
                    ->orderByDesc('as_of')
                    ->orderByDesc('id')
                    ->get()
                    ->unique('valuable_id')
                    ->keyBy('valuable_id');

                foreach ($assets as $asset) {
                    $v = $latest->get($asset->id);
                    if ($v) {
                        $sum += (float) $v->value;
                    } elseif ($asset->{$fallback} !== null) {
                        $sum += (float) $asset->{$fallback};
                    }
                }
            }
            $byKind[$bucket] = $sum;
            $assetsTotal += $sum;
        }

        $sparkline = Snapshot::where('kind', 'net_worth')
            ->whereNull('subject_type')
            ->orderByDesc('taken_on')
            ->limit(12)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Snapshot $s) => [
                'taken_on' => $s->taken_on?->toDateString() ?? '',
                'total' => (float) ($s->payload['total'] ?? 0),
            ])
            ->all();

        return [
            'total' => round($accountsTotal + $assetsTotal, 2),
            'accounts' => round($accountsTotal, 2),
            'assets' => round($assetsTotal, 2),
            'by_kind' => array_map(fn ($v) => round($v, 2), $byKind),
            'sparkline' => $sparkline,
        ];
    }

    /**
     * Per-user net worth split so multi-user households see who holds what.
     *
     * @return array<int, array{id: ?int, name: string, total: float}>
     */
    #[Computed]
    public function netWorthByUser(): array
    {
        $users = User::query()
            ->whereHas('households', fn ($q) => $q->where('households.id', CurrentHousehold::id()))
            ->get(['id', 'name']);

        $rows = [];
        $sharedTotal = 0.0;

        // Accounts — by user_id; null user_id rolls into shared. Shares
        // the netWorthAccounts + netWorthBalances computeds with netWorth()
        // so the same data doesn't get re-queried per call site.
        $balances = $this->netWorthBalances;
        $accountsByUser = $this->netWorthAccounts->groupBy(fn (Account $a) => $a->user_id);

        foreach ($users as $u) {
            $sum = 0.0;
            foreach ($accountsByUser->get($u->id, collect()) as $a) {
                $sum += $balances[$a->id] ?? 0.0;
            }
            $rows[] = ['id' => $u->id, 'name' => $u->name, 'total' => round($sum, 2)];
        }

        foreach ($accountsByUser->get(null, collect()) as $a) {
            $sharedTotal += $balances[$a->id] ?? 0.0;
        }
        if (abs($sharedTotal) > 0.01) {
            $rows[] = ['id' => null, 'name' => __('Shared'), 'total' => round($sharedTotal, 2)];
        }

        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $rows;
    }

    /**
     * @return array{income: float, expense: float, net: float}
     */
    #[Computed]
    public function cashflow(): array
    {
        $income = (float) Transaction::whereBetween('occurred_on', [
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        ])
            ->where('status', 'cleared')
            ->where('amount', '>', 0)
            ->sum('amount');

        $expense = (float) Transaction::whereBetween('occurred_on', [
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        ])
            ->where('status', 'cleared')
            ->where('amount', '<', 0)
            ->sum('amount');

        return [
            'income' => round($income, 2),
            'expense' => round(abs($expense), 2),
            'net' => round($income + $expense, 2),
        ];
    }

    /**
     * Obligations by window — next 30 / 60 / 90 days of projected bills.
     *
     * @return array{d30: float, d60: float, d90: float}
     */
    #[Computed]
    public function obligations(): array
    {
        $base = RecurringProjection::query()
            ->whereIn('status', ['projected', 'matched', 'overdue'])
            ->where('amount', '<', 0);

        return [
            'd30' => round(abs((float) (clone $base)->whereBetween('due_on', [now()->toDateString(), now()->addDays(30)->toDateString()])->sum('amount')), 2),
            'd60' => round(abs((float) (clone $base)->whereBetween('due_on', [now()->toDateString(), now()->addDays(60)->toDateString()])->sum('amount')), 2),
            'd90' => round(abs((float) (clone $base)->whereBetween('due_on', [now()->toDateString(), now()->addDays(90)->toDateString()])->sum('amount')), 2),
        ];
    }

    /**
     * @return array{monthly: float, count: int}
     */
    #[Computed]
    public function subscriptionBurn(): array
    {
        $active = Contract::where('state', 'active')
            ->whereIn('kind', ['subscription', 'insurance'])
            ->whereNotNull('monthly_cost_amount')
            ->get();

        return [
            'monthly' => round((float) $active->sum('monthly_cost_amount'), 2),
            'count' => $active->count(),
        ];
    }

    /**
     * Gift cards still usable (active, non-zero balance).
     *
     * @return array{face_value: float, remaining: float, count: int, expiring_soon: int}
     */
    #[Computed]
    public function giftCards(): array
    {
        $giftCards = Account::whereIn('type', ['gift_card', 'prepaid'])
            ->where('is_active', true)
            ->get();

        $faceValue = 0.0;
        $remaining = 0.0;
        $expiringSoon = 0;

        // Gift cards don't participate in transfers — a single SUM-by-account_id
        // query covers every card's activity instead of looping one query per
        // card. Falls back to 0 for cards with no transactions yet.
        $txnSums = Transaction::whereIn('account_id', $giftCards->pluck('id'))
            ->where('status', 'cleared')
            ->selectRaw('account_id, SUM(amount) as total')
            ->groupBy('account_id')
            ->pluck('total', 'account_id');

        foreach ($giftCards as $gc) {
            $faceValue += (float) $gc->opening_balance;
            $bal = (float) $gc->opening_balance + (float) ($txnSums[$gc->id] ?? 0);
            $remaining += $bal;

            if ($gc->expires_on && now()->startOfDay()->diffInDays($gc->expires_on, absolute: false) <= 30 && now()->startOfDay()->diffInDays($gc->expires_on, absolute: false) >= 0) {
                $expiringSoon++;
            }
        }

        return [
            'face_value' => round($faceValue, 2),
            'remaining' => round($remaining, 2),
            'count' => $giftCards->count(),
            'expiring_soon' => $expiringSoon,
        ];
    }

    /**
     * @return array{count: int, next_cancel_by: ?string}
     */
    #[Computed]
    public function trials(): array
    {
        $trials = Contract::whereNotNull('trial_ends_on')
            ->whereDate('trial_ends_on', '>=', now()->toDateString())
            ->orderBy('trial_ends_on')
            ->get();

        return [
            'count' => $trials->count(),
            'next_cancel_by' => $trials->first()?->trial_ends_on?->toDateString(),
        ];
    }
};
?>

<div class="space-y-5">
    <header>
        <h2 class="text-base font-semibold text-neutral-100">{{ __('Finance overview') }}</h2>
        <p class="mt-1 text-xs text-neutral-500">{{ __('Consolidated household-wide money view.') }}</p>
    </header>

    {{-- Net worth banner + sparkline --}}
    <section class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <div class="text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __('Household net worth') }}</div>
                <div class="mt-1 text-3xl font-semibold tabular-nums text-neutral-100">
                    {{ Formatting::money($this->netWorth['total'], $this->currency) }}
                </div>
                <div class="mt-1 text-[11px] text-neutral-500">
                    {{ __('Accounts') }} {{ Formatting::money($this->netWorth['accounts'], $this->currency) }} · {{ __('Assets') }} {{ Formatting::money($this->netWorth['assets'], $this->currency) }}
                </div>
            </div>
            @if (count($this->netWorth['sparkline']) >= 2)
                @php
                    $values = array_map(fn ($p) => $p['total'], $this->netWorth['sparkline']);
                    $min = min($values);
                    $max = max($values);
                    $range = ($max - $min) ?: 1;
                    $w = 160;
                    $h = 40;
                    $step = count($values) > 1 ? $w / (count($values) - 1) : 0;
                    $points = [];
                    foreach ($values as $i => $v) {
                        $x = round($i * $step, 2);
                        $y = round($h - (($v - $min) / $range) * $h, 2);
                        $points[] = "$x,$y";
                    }
                    $path = implode(' ', $points);
                    $delta = end($values) - reset($values);
                    $trendClass = $delta >= 0 ? 'text-emerald-400' : 'text-rose-400';
                @endphp
                <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-10 w-40 {{ $trendClass }}" aria-label="{{ __('Net worth trend') }}" role="img">
                    <polyline fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" points="{{ $path }}" />
                </svg>
            @endif
        </div>

        {{-- Breakdown by kind --}}
        @php
            $kindLabels = array_merge(Enums::accountTypes(), [
                'property' => __('Property'),
                'vehicle' => __('Vehicle'),
                'inventory' => __('Inventory'),
            ]);
            $shown = array_filter($this->netWorth['by_kind'], fn ($v) => abs($v) > 0.01);
        @endphp
        @if (count($shown))
            <dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($shown as $kind => $amount)
                    <div>
                        <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ $kindLabels[$kind] ?? $kind }}</dt>
                        <dd class="mt-0.5 tabular-nums text-sm {{ $amount >= 0 ? 'text-neutral-200' : 'text-rose-400' }}">
                            {{ Formatting::money($amount, $this->currency) }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>

    {{-- Three-up: cashflow, obligations, subscription burn --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <section class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
            <h3 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('This month') }}</h3>
            <div class="mt-3 space-y-1 text-sm">
                <div class="flex items-baseline justify-between">
                    <span class="text-neutral-500">{{ __('Income') }}</span>
                    <span class="tabular-nums text-emerald-400">+{{ Formatting::money($this->cashflow['income'], $this->currency) }}</span>
                </div>
                <div class="flex items-baseline justify-between">
                    <span class="text-neutral-500">{{ __('Expense') }}</span>
                    <span class="tabular-nums text-rose-400">−{{ Formatting::money($this->cashflow['expense'], $this->currency) }}</span>
                </div>
                <div class="mt-1 flex items-baseline justify-between border-t border-neutral-800 pt-1">
                    <span class="text-neutral-300">{{ __('Net') }}</span>
                    <span class="tabular-nums {{ $this->cashflow['net'] >= 0 ? 'text-emerald-400' : 'text-rose-400' }} font-semibold">
                        {{ $this->cashflow['net'] >= 0 ? '+' : '' }}{{ Formatting::money($this->cashflow['net'], $this->currency) }}
                    </span>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
            <h3 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Obligations ahead') }}</h3>
            <div class="mt-3 space-y-1 text-sm">
                <div class="flex items-baseline justify-between">
                    <span class="text-neutral-500">{{ __('Next 30d') }}</span>
                    <span class="tabular-nums text-rose-400">−{{ Formatting::money($this->obligations['d30'], $this->currency) }}</span>
                </div>
                <div class="flex items-baseline justify-between">
                    <span class="text-neutral-500">{{ __('Next 60d') }}</span>
                    <span class="tabular-nums text-rose-300">−{{ Formatting::money($this->obligations['d60'], $this->currency) }}</span>
                </div>
                <div class="flex items-baseline justify-between">
                    <span class="text-neutral-500">{{ __('Next 90d') }}</span>
                    <span class="tabular-nums text-neutral-400">−{{ Formatting::money($this->obligations['d90'], $this->currency) }}</span>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
            <h3 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Subscription burn') }}</h3>
            <div class="mt-3 space-y-1 text-sm">
                <div class="flex items-baseline justify-between">
                    <span class="text-neutral-500">{{ __('Monthly') }}</span>
                    <span class="tabular-nums text-neutral-200">{{ Formatting::money($this->subscriptionBurn['monthly'], $this->currency) }}</span>
                </div>
                <div class="flex items-baseline justify-between">
                    <span class="text-neutral-500">{{ __('Active contracts') }}</span>
                    <span class="tabular-nums text-neutral-200">{{ $this->subscriptionBurn['count'] }}</span>
                </div>
                @if ($this->trials['count'] > 0)
                    <div class="mt-1 flex items-baseline justify-between border-t border-neutral-800 pt-1">
                        <span class="text-neutral-500">{{ __('Trials to cancel') }}</span>
                        <span class="tabular-nums text-amber-400">{{ $this->trials['count'] }}
                            @if ($this->trials['next_cancel_by'])
                                · {{ Formatting::date($this->trials['next_cancel_by']) }}
                            @endif
                        </span>
                    </div>
                @endif
            </div>
        </section>
    </div>

    {{-- Per-user split (only when >1 user or shared accounts exist) --}}
    @if (count($this->netWorthByUser) > 1)
        <section class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
            <h3 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Per-user split') }}</h3>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($this->netWorthByUser as $row)
                    <li class="flex items-baseline justify-between">
                        <span class="text-neutral-300">{{ $row['name'] }}</span>
                        <span class="tabular-nums {{ $row['total'] >= 0 ? 'text-neutral-200' : 'text-rose-400' }}">
                            {{ Formatting::money($row['total'], $this->currency) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Gift cards (only when present) --}}
    @if ($this->giftCards['count'] > 0)
        <section class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
            <h3 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Gift cards + prepaid') }}</h3>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Cards') }}</dt>
                    <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->giftCards['count'] }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Face value') }}</dt>
                    <dd class="mt-0.5 tabular-nums text-neutral-200">{{ Formatting::money($this->giftCards['face_value'], $this->currency) }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Remaining') }}</dt>
                    <dd class="mt-0.5 tabular-nums text-emerald-400">{{ Formatting::money($this->giftCards['remaining'], $this->currency) }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Expiring ≤ 30d') }}</dt>
                    <dd class="mt-0.5 tabular-nums {{ $this->giftCards['expiring_soon'] > 0 ? 'text-rose-400' : 'text-neutral-500' }}">
                        {{ $this->giftCards['expiring_soon'] }}
                    </dd>
                </div>
            </dl>
        </section>
    @endif
</div>

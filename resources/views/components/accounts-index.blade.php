<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Support\Formatting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Accounts'])]
class extends Component
{
    #[Url(as: 'sort')]
    public string $sortBy = 'type';

    #[Url(as: 'dir')]
    public string $sortDir = 'asc';

    #[On('inspector-saved')]
    public function refresh(string $type = ''): void
    {
        if (in_array($type, ['account', 'transaction', 'bill'], true)) {
            unset($this->accounts, $this->netWorth);
        }
    }

    public function sort(string $column): void
    {
        if (! in_array($column, ['name', 'type', 'institution', 'current_balance'], true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    #[Computed]
    public function accounts()
    {
        $typeOrder = [
            'bank' => 0, 'credit' => 1, 'cash' => 2, 'investment' => 3,
            'loan' => 4, 'mortgage' => 5, 'gift_card' => 6, 'prepaid' => 7,
        ];

        $accounts = Account::with(['vendor:id,display_name', 'loanTerms:id,account_id,interest_rate,rate_type'])
            ->where(fn ($q) => $q->where('user_id', auth()->id())->orWhereNull('user_id'))
            ->orderBy('name')
            ->get();

        $accountIds = $accounts->pluck('id');

        $txnSums = Transaction::whereIn('account_id', $accountIds)
            ->where('status', 'cleared')
            ->selectRaw('account_id, SUM(amount) as total')
            ->groupBy('account_id')
            ->pluck('total', 'account_id');

        $transferOut = Transfer::whereIn('from_account_id', $accountIds)
            ->where('status', 'cleared')
            ->selectRaw('from_account_id, SUM(from_amount) as total')
            ->groupBy('from_account_id')
            ->pluck('total', 'from_account_id');

        $transferIn = Transfer::whereIn('to_account_id', $accountIds)
            ->where('status', 'cleared')
            ->selectRaw('to_account_id, SUM(to_amount) as total')
            ->groupBy('to_account_id')
            ->pluck('total', 'to_account_id');

        $withBalances = $accounts->map(function (Account $a) use ($txnSums, $transferOut, $transferIn) {
            $txn = (float) ($txnSums[$a->id] ?? 0);
            $out = (float) ($transferOut[$a->id] ?? 0);
            $in = (float) ($transferIn[$a->id] ?? 0);
            $a->setAttribute('current_balance', (float) $a->opening_balance + $txn - $out + $in);

            $a->setAttribute('effective_rate', \App\Support\EffectiveRate::forAccount($a));

            return $a;
        });

        $key = match ($this->sortBy) {
            'name' => fn (Account $a) => strtolower((string) $a->name),
            'type' => fn (Account $a) => ($typeOrder[$a->type] ?? 99).'-'.strtolower((string) $a->name),
            'institution' => fn (Account $a) => strtolower((string) ($a->institution ?? '~')),
            'current_balance' => fn (Account $a) => (float) $a->current_balance,
            default => fn (Account $a) => strtolower((string) $a->name),
        };

        $sorted = $withBalances->sortBy($key, SORT_REGULAR, $this->sortDir === 'desc')->values();

        return $sorted;
    }

    #[Computed]
    public function netWorth(): float
    {
        return (float) $this->accounts
            ->filter(fn (Account $a) => $a->include_in_net_worth && $a->is_active)
            ->sum('current_balance');
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Accounts') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Balances are opening balance + cleared transactions and transfers.') }}</p>
        </div>
        <div class="flex items-center gap-4">
            <div class="text-right">
                <div class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Net worth') }}</div>
                <div class="mt-0.5 text-lg font-semibold tabular-nums text-neutral-100">
                    {{ Formatting::money($this->netWorth) }}
                </div>
            </div>
            <x-ui.new-record-button type="account" :label="__('New account')" shortcut="A" />
        </div>
    </header>

    @if ($this->accounts->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No accounts yet.') }}
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-neutral-800 bg-neutral-900/40">
            <table class="w-full min-w-[40rem] text-sm">
                <caption class="sr-only">{{ __('Your accounts') }}</caption>
                <thead class="border-b border-neutral-800 text-left">
                    <tr>
                        <x-ui.sortable-header column="name" :label="__('Account')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-ui.sortable-header column="type" :label="__('Type')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-ui.sortable-header column="institution" :label="__('Institution')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-ui.sortable-header column="current_balance" :label="__('Balance')" :sort-by="$sortBy" :sort-dir="$sortDir" align="right" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800/60">
                    @foreach ($this->accounts as $account)
                        <tr tabindex="0" role="button"
                            wire:key="acct-{{ $account->id }}"
                            wire:click="$dispatch('inspector-open', {{ json_encode(['type' => 'account', 'id' => $account->id]) }})"
                            @keydown.enter.prevent="$wire.dispatch('inspector-open', {{ json_encode(['type' => 'account', 'id' => $account->id]) }})"
                            class="cursor-pointer transition hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <td class="px-4 py-3">
                                <div class="font-medium text-neutral-100">
                                    {{ $account->name }}
                                    @unless ($account->is_active)
                                        <span class="ml-2 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ __('Closed') }}</span>
                                    @endunless
                                </div>
                                @if ($account->external_code ?? null)
                                    <div class="text-[11px] text-neutral-500 tabular-nums">{{ $account->external_code }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-neutral-400">
                                {{ App\Support\Enums::accountTypes()[$account->type] ?? ucfirst($account->type) }}
                                @if ($account->expires_on && in_array($account->type, ['gift_card', 'prepaid'], true))
                                    @php
                                        $gcDays = (int) now()->startOfDay()->diffInDays($account->expires_on, absolute: false);
                                        $gcClass = match (true) {
                                            $gcDays < 0 => 'text-neutral-600',
                                            $gcDays <= 30 => 'text-rose-400',
                                            $gcDays <= 90 => 'text-amber-400',
                                            default => 'text-neutral-500',
                                        };
                                    @endphp
                                    <div class="mt-0.5 text-[11px] {{ $gcClass }}">@if ($gcDays < 0){{ __('expired') }}@else{{ __('Expires') }} {{ \App\Support\Formatting::date($account->expires_on) }} · {{ $gcDays }}d @endif</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-neutral-400">
                                @if ($account->institution)
                                    {{ $account->institution }}
                                    @if ($account->vendor)
                                        <div class="text-[11px] text-neutral-500">{{ $account->vendor->display_name }}</div>
                                    @endif
                                @elseif ($account->vendor)
                                    {{ $account->vendor->display_name }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums {{ $account->current_balance >= 0 ? 'text-neutral-100' : 'text-rose-400' }}">
                                {{ Formatting::money($account->current_balance, $account->currency) }}
                                @if ($account->effective_rate)
                                    @php
                                        $rateSign = in_array($account->type, ['credit', 'loan', 'mortgage'], true) ? 'text-rose-400' : 'text-emerald-400';
                                    @endphp
                                    <div class="mt-0.5 text-[10px] tabular-nums {{ $rateSign }}" title="{{ __(':n month(s) evaluated', ['n' => $account->effective_rate['months_evaluated']]) }}">
                                        {{ number_format($account->effective_rate['apr'] * 100, 2) }}% APR · {{ number_format($account->effective_rate['apy'] * 100, 2) }}% APY
                                    </div>
                                @endif
                                @if (in_array($account->type, ['loan', 'mortgage'], true) && $account->loanTerms?->interest_rate !== null)
                                    @php
                                        $contract = (float) $account->loanTerms->interest_rate;
                                        $drift = $account->effective_rate
                                            ? ($account->effective_rate['apr'] * 100) - $contract
                                            : null;
                                        $driftClass = match (true) {
                                            $drift === null => 'text-neutral-500',
                                            abs($drift) > 1.0 => 'text-rose-400',
                                            abs($drift) > 0.5 => 'text-amber-400',
                                            default => 'text-neutral-500',
                                        };
                                        $rateTypeLabel = $account->loanTerms->rate_type === 'variable' ? __('variable') : __('fixed');
                                    @endphp
                                    <div class="mt-0.5 text-[10px] tabular-nums {{ $driftClass }}"
                                         title="{{ __('Contract rate from loan terms') }}">
                                        {{ number_format($contract, 2) }}% {{ $rateTypeLabel }}@if ($drift !== null) · {{ $drift >= 0 ? '+' : '' }}{{ number_format($drift, 2) }}% {{ __('drift') }}@endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

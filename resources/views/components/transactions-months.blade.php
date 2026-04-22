<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One-row-per-month rollup across every transaction in the household.
 * Account + status filters narrow the set, but there's no date range —
 * the whole point is seeing the full history so you can jump to any
 * month. Clicking a row deep-links to the Transactions tab with
 * from/to pinned to that month.
 */
new
#[Layout('components.layouts.app', ['title' => 'Months'])]
class extends Component
{
    #[Url(as: 'account')]
    public string $accountId = '';

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'sort')]
    public string $sortBy = 'ym';

    #[Url(as: 'dir')]
    public string $sortDir = 'desc';

    private const SORTABLE = ['ym', 'count', 'credits', 'debits', 'net'];

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->months);
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            // Month newest-first and net-largest-first match how people skim
            // these columns; the magnitude cols also default to desc since
            // "biggest month" is the common question. Count is the only one
            // where asc ("quietest month") reads as more natural.
            $this->sortBy = $column;
            $this->sortDir = $column === 'count' ? 'asc' : 'desc';
        }
        unset($this->months);
    }

    #[Computed]
    public function accounts()
    {
        return Account::orderBy('name')->get(['id', 'name', 'currency']);
    }

    #[Computed]
    public function currency(): string
    {
        if ($this->accountId !== '') {
            $acct = Account::find($this->accountId);
            if ($acct !== null && is_string($acct->currency) && $acct->currency !== '') {
                return $acct->currency;
            }
        }

        return CurrentHousehold::get()?->default_currency ?? 'USD';
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{ym: string, count: int, credits: float, debits: float, net: float}>
     */
    #[Computed]
    public function months(): \Illuminate\Support\Collection
    {
        // sort() whitelists the column; dir is asc|desc from the toggle.
        // orderByRaw is safe here because the column name never comes
        // from user input directly — it's always one of SORTABLE.
        $col = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'ym';
        $dir = $this->sortDir === 'asc' ? 'asc' : 'desc';
        $sqlColumn = $col === 'count' ? 'n' : $col;

        return Transaction::query()
            ->when($this->accountId !== '', fn ($q) => $q->where('account_id', $this->accountId))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->selectRaw("
                DATE_FORMAT(occurred_on, '%Y-%m') as ym,
                COUNT(*) as n,
                SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as credits,
                SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) as debits,
                SUM(amount) as net
            ")
            ->groupBy('ym')
            ->orderByRaw("{$sqlColumn} {$dir}")
            ->get()
            ->map(fn ($row) => [
                'ym' => (string) $row->ym,
                'count' => (int) $row->n,
                'credits' => (float) $row->credits,
                'debits' => (float) $row->debits,
                'net' => (float) $row->net,
            ]);
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Months') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">
                {{ __('One row per month, across all transactions. Click a month to jump to the list.') }}
            </p>
        </div>
    </header>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="m-acct" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Account') }}</label>
            <select wire:model.live="accountId" id="m-acct"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All accounts') }}</option>
                @foreach ($this->accounts as $a)
                    <option value="{{ $a->id }}">{{ $a->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="m-status" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Status') }}</label>
            <select wire:model.live="status" id="m-status"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('Any') }}</option>
                @foreach (App\Support\Enums::transactionStatuses() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if ($this->months->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No transactions yet.') }}
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-neutral-800 bg-neutral-900/40">
            <table class="w-full min-w-[40rem] text-sm">
                <thead class="border-b border-neutral-800 text-left">
                    <tr>
                        <x-ui.sortable-header column="ym" :label="__('Month')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-ui.sortable-header column="count" :label="__('Count')" :sort-by="$sortBy" :sort-dir="$sortDir" align="right" />
                        <x-ui.sortable-header column="credits" :label="__('In')" :sort-by="$sortBy" :sort-dir="$sortDir" align="right" />
                        <x-ui.sortable-header column="debits" :label="__('Out')" :sort-by="$sortBy" :sort-dir="$sortDir" align="right" />
                        <x-ui.sortable-header column="net" :label="__('Net')" :sort-by="$sortBy" :sort-dir="$sortDir" align="right" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800/60">
                    @foreach ($this->months as $m)
                        @php
                            $start = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $m['ym']);
                            $startDate = $start ? $start->startOfMonth()->toDateString() : null;
                            $endDate = $start ? $start->endOfMonth()->toDateString() : null;
                            $label = $start ? $start->format('F Y') : $m['ym'];
                            $params = ['tab' => 'transactions', 'from' => $startDate, 'to' => $endDate];
                            if ($accountId !== '') {
                                $params['account'] = $accountId;
                            }
                            if ($status !== '') {
                                $params['status'] = $status;
                            }
                        @endphp
                        <tr wire:key="m-row-{{ $m['ym'] }}" class="transition hover:bg-neutral-800/30">
                            <td class="px-3 py-2">
                                <a href="{{ route('fiscal.ledger', $params) }}" wire:navigate
                                   class="block font-medium text-neutral-100 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    {{ $label }}
                                </a>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-neutral-300">{{ $m['count'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-emerald-400">
                                @if ($m['credits'] > 0)
                                    +{{ Formatting::money($m['credits'], $this->currency) }}
                                @else
                                    <span class="text-neutral-600">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-rose-400">
                                @if ($m['debits'] < 0)
                                    {{ Formatting::money($m['debits'], $this->currency) }}
                                @else
                                    <span class="text-neutral-600">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums {{ $m['net'] >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                                {{ ($m['net'] >= 0 ? '+' : '').Formatting::money($m['net'], $this->currency) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

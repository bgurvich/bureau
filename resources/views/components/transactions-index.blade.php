<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.app', ['title' => 'Transactions'])]
class extends Component
{
    use WithPagination;

    #[Url(as: 'account')]
    public string $accountId = '';

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'tag')]
    public string $tagFilter = '';

    #[Url(as: 'category')]
    public string $categoryFilter = '';

    #[Url(as: 'sort')]
    public string $sortBy = 'occurred_on';

    #[Url(as: 'dir')]
    public string $sortDir = 'desc';

    public function sort(string $column): void
    {
        if (! in_array($column, ['occurred_on', 'description', 'amount', 'status'], true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = $column === 'occurred_on' ? 'desc' : 'asc';
        }

        $this->resetPage();
    }

    public function mount(): void
    {
        if ($this->from === '') {
            $this->from = now()->subMonths(3)->toDateString();
        }
        if ($this->to === '') {
            $this->to = now()->toDateString();
        }
    }

    public function updatingAccountId(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['accountId', 'status', 'search']);
        $this->from = now()->subMonths(3)->toDateString();
        $this->to = now()->toDateString();
        $this->resetPage();
    }

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->transactions, $this->totals);
    }

    #[Computed]
    public function accounts()
    {
        return Account::orderBy('name')->get();
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

    #[Computed]
    public function transactions()
    {
        return Transaction::query()
            ->with([
                'account:id,name,currency',
                'category:id,name,slug',
                'counterparty:id,display_name',
                'tags:id,name,slug',
                'media' => fn ($q) => $q->where('mime', 'like', 'image/%'),
            ])
            ->when($this->accountId !== '', fn ($q) => $q->where('account_id', $this->accountId))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->tagFilter !== '', fn ($q) => $q
                ->whereHas('tags', fn ($t) => $t->where('slug', $this->tagFilter))
            )
            ->when($this->categoryFilter !== '', fn ($q) => $q->where('category_id', $this->categoryFilter))
            ->whereDate('occurred_on', '>=', $this->from)
            ->whereDate('occurred_on', '<=', $this->to)
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('description', 'like', $term)
                    ->orWhereRaw('CAST(amount AS CHAR) LIKE ?', [$term])
                );
            })
            ->orderBy($this->sortBy, $this->sortDir)
            ->orderByDesc('id')
            ->paginate(50);
    }

    #[Computed]
    public function totals(): array
    {
        $q = Transaction::query()
            ->when($this->accountId !== '', fn ($q) => $q->where('account_id', $this->accountId))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->whereDate('occurred_on', '>=', $this->from)
            ->whereDate('occurred_on', '<=', $this->to);

        $credits = (clone $q)->where('amount', '>', 0)->sum('amount');
        $debits = (clone $q)->where('amount', '<', 0)->sum('amount');

        return [
            'credits' => (float) $credits,
            'debits' => (float) $debits,
            'net' => (float) $credits + (float) $debits,
            'count' => (clone $q)->count(),
        ];
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Transactions') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Filter by account, status, date, or free text.') }}</p>
        </div>
        <x-ui.new-record-button type="transaction" :label="__('New transaction')" shortcut="X" />
    </header>

    <dl class="flex gap-6 text-right">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('In') }}</dt>
                <dd class="mt-0.5 text-sm tabular-nums text-emerald-400">+{{ Formatting::money($this->totals['credits'], $this->currency) }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Out') }}</dt>
                <dd class="mt-0.5 text-sm tabular-nums text-rose-400">{{ Formatting::money($this->totals['debits'], $this->currency) }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Net') }}</dt>
                <dd class="mt-0.5 text-sm tabular-nums {{ $this->totals['net'] >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                    {{ ($this->totals['net'] >= 0 ? '+' : '').Formatting::money($this->totals['net'], $this->currency) }}
                </dd>
            </div>
    </dl>

    <form wire:submit.prevent
          class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4"
          aria-label="{{ __('Filters') }}">
        <div>
            <label for="q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="q" type="text" placeholder="{{ __('Description or amount…') }}"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="f-acct" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Account') }}</label>
            <select wire:model.live="accountId" id="f-acct"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All accounts') }}</option>
                @foreach ($this->accounts as $a)
                    <option value="{{ $a->id }}">{{ $a->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-status" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Status') }}</label>
            <select wire:model.live="status" id="f-status"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('Any') }}</option>
                @foreach (App\Support\Enums::transactionStatuses() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-from" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('From') }}</label>
            <input wire:model.live="from" id="f-from" type="date"
                   class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="f-to" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('To') }}</label>
            <input wire:model.live="to" id="f-to" type="date"
                   class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <button type="button" wire:click="clearFilters"
                class="ml-auto rounded-md border border-neutral-800 px-3 py-1.5 text-xs text-neutral-400 hover:border-neutral-700 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            {{ __('Clear') }}
        </button>
    </form>

    @if ($tagFilter !== '')
        <div role="status" class="flex items-center justify-between rounded-lg border border-emerald-800/40 bg-emerald-900/20 px-4 py-2 text-sm text-emerald-200">
            <span class="font-mono">{{ __('Filtering by') }} #{{ $tagFilter }}</span>
            <button type="button" wire:click="$set('tagFilter', '')"
                    class="rounded-md px-2 py-1 text-xs text-emerald-200 hover:bg-emerald-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ __('Clear') }}
            </button>
        </div>
    @endif

    @if ($this->transactions->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No transactions match those filters.') }}
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-neutral-800 bg-neutral-900/40">
            <table class="w-full text-sm">
                <thead class="border-b border-neutral-800 text-left">
                    <tr>
                        <th scope="col" class="w-10 px-2 py-2"><span class="sr-only">{{ __('Scan') }}</span></th>
                        <x-ui.sortable-header column="occurred_on" :label="__('Date')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-ui.sortable-header column="description" :label="__('Description')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <th scope="col" class="px-3 py-2 text-left text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Counterparty') }}</th>
                        <th scope="col" class="px-3 py-2 text-left text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Category') }}</th>
                        <th scope="col" class="px-3 py-2 text-left text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Account') }}</th>
                        <x-ui.sortable-header column="amount" :label="__('Amount')" :sort-by="$sortBy" :sort-dir="$sortDir" align="right" />
                        <x-ui.sortable-header column="status" :label="__('Status')" :sort-by="$sortBy" :sort-dir="$sortDir" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800/60">
                    @foreach ($this->transactions as $t)
                        @php($scan = $t->media->firstWhere('pivot.role', 'receipt') ?? $t->media->first())
                        <tr wire:click="$dispatch('inspector-open', { type: 'transaction', id: {{ $t->id }} })"
                            class="cursor-pointer transition hover:bg-neutral-800/30">
                            <td class="px-2 py-2">
                                @if ($scan)
                                    <a href="{{ route('records.media', ['focus' => $scan->id]) }}"
                                       wire:click.stop
                                       title="{{ __('Open scan') }}"
                                       aria-label="{{ __('Open scan') }}"
                                       class="block h-8 w-8 overflow-hidden rounded border border-neutral-800 bg-neutral-950 hover:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                        <img src="{{ route('media.file', $scan) }}" alt="" loading="lazy"
                                             class="h-full w-full object-cover opacity-80 hover:opacity-100" />
                                    </a>
                                @else
                                    <span aria-hidden="true" class="block h-8 w-8 rounded border border-dashed border-neutral-800/60"></span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs text-neutral-400 tabular-nums">{{ Formatting::date($t->occurred_on) }}</td>
                            <td class="px-3 py-2 text-neutral-100">
                                {{ $t->description ?? '—' }}
                                @if ($t->reference_number ?? null)
                                    <span class="ml-2 text-[11px] text-neutral-500 tabular-nums">#{{ $t->reference_number }}</span>
                                @endif
                                <x-ui.tag-chips :tags="$t->tags" :active="$tagFilter" class="mt-1" />
                            </td>
                            <td class="px-3 py-2 text-xs text-neutral-400">{{ $t->counterparty?->display_name ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs text-neutral-400">{{ $t->category?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs text-neutral-400">{{ $t->account?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums {{ $t->amount >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                                {{ ($t->amount >= 0 ? '+' : '').Formatting::money((float) $t->amount, $t->account?->currency ?? $this->currency) }}
                            </td>
                            <td class="px-3 py-2 text-[11px] uppercase tracking-wider {{ $t->status === 'cleared' ? 'text-neutral-500' : 'text-amber-400' }}">
                                {{ $t->status }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div>{{ $this->transactions->links() }}</div>
    @endif
</div>

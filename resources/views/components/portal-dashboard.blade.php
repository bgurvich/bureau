<?php

use App\Models\Account;
use App\Models\PortalGrant;
use App\Models\Transaction;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only fiscal dashboard for an external bookkeeper / CPA. Scoped
 * automatically to the grant's household via EnsurePortalSession +
 * CurrentHousehold. No editing affordances — only listings + exports.
 *
 * Intentionally a separate component from the owner-facing ledger so
 * there's zero risk of a mutation-shaped dispatch (wire:click="delete",
 * inspector drawer, etc.) sneaking into the portal surface through
 * shared code.
 */
new
#[Layout('components.layouts.portal', ['title' => 'Bookkeeper portal'])]
class extends Component
{
    use WithPagination;

    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    #[Computed]
    public function grant(): ?PortalGrant
    {
        $id = session('portal_grant_id');

        return $id ? PortalGrant::query()->withoutGlobalScope('household')->find($id) : null;
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'currency']);
    }

    #[Computed]
    public function transactions(): LengthAwarePaginator
    {
        $q = Transaction::query()
            ->with(['account:id,name,currency', 'category:id,name', 'counterparty:id,display_name'])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id');
        if ($this->from !== '') {
            $q->whereDate('occurred_on', '>=', $this->from);
        }
        if ($this->to !== '') {
            $q->whereDate('occurred_on', '<=', $this->to);
        }

        return $q->paginate(50);
    }

    #[Computed]
    public function totalCount(): int
    {
        $q = Transaction::query();
        if ($this->from !== '') {
            $q->whereDate('occurred_on', '>=', $this->from);
        }
        if ($this->to !== '') {
            $q->whereDate('occurred_on', '<=', $this->to);
        }

        return $q->count();
    }

    public function currency(): string
    {
        return CurrentHousehold::get()?->default_currency ?? 'USD';
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Fiscal overview') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">
                @if ($this->grant?->label)
                    {{ $this->grant->label }} ·
                @endif
                {{ __('Read-only view scoped to this household. No mutations are exposed.') }}
            </p>
        </div>
        <form method="post" action="{{ route('portal.logout') }}" class="shrink-0">
            @csrf
            <button type="submit"
                    class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-200 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ __('Sign out') }}
            </button>
        </form>
    </header>

    <section class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-4">
        <h3 class="mb-2 text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Active accounts') }}</h3>
        <ul class="divide-y divide-neutral-800/60 text-sm">
            @forelse ($this->accounts as $a)
                <li class="flex items-baseline justify-between gap-3 py-1.5">
                    <span class="text-neutral-100">{{ $a->name }}</span>
                    <span class="text-[11px] text-neutral-500">{{ $a->type }} · {{ $a->currency ?? $this->currency() }}</span>
                </li>
            @empty
                <li class="py-2 text-neutral-500">{{ __('No active accounts.') }}</li>
            @endforelse
        </ul>
    </section>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-xl border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="prt-from" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('From') }}</label>
            <input wire:model.live.debounce.300ms="from" id="prt-from" type="date"
                   class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="prt-to" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('To') }}</label>
            <input wire:model.live.debounce.300ms="to" id="prt-to" type="date"
                   class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <span class="flex-1"></span>
        <a href="{{ route('portal.export', ['from' => $from, 'to' => $to]) }}"
           class="rounded-md border border-emerald-800/50 bg-emerald-900/20 px-3 py-1.5 text-xs text-emerald-200 hover:bg-emerald-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            {{ __('Export CSV') }}
        </a>
    </form>

    <section class="overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
        <div class="flex items-baseline justify-between border-b border-neutral-800 px-4 py-2 text-[11px] text-neutral-500">
            <span>{{ __('Transactions') }}</span>
            <span class="tabular-nums">{{ __(':n total', ['n' => $this->totalCount]) }}</span>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-neutral-900/60 text-[10px] uppercase tracking-wider text-neutral-500">
                <tr>
                    <th class="px-3 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Description') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Counterparty') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Category') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-800/60">
                @forelse ($this->transactions as $t)
                    <tr>
                        <td class="px-3 py-1.5 text-xs text-neutral-400 tabular-nums">{{ Formatting::date($t->occurred_on) }}</td>
                        <td class="px-3 py-1.5 text-neutral-100">{{ $t->description ?: '—' }}</td>
                        <td class="px-3 py-1.5 text-xs text-neutral-400">{{ $t->counterparty?->display_name ?? '—' }}</td>
                        <td class="px-3 py-1.5 text-xs text-neutral-400">{{ $t->category?->name ?? '—' }}</td>
                        <td class="px-3 py-1.5 text-xs text-neutral-400">{{ $t->account?->name ?? '—' }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums {{ (float) $t->amount < 0 ? 'text-neutral-100' : 'text-emerald-400' }}">
                            {{ Formatting::money((float) $t->amount, $t->currency ?? ($t->account?->currency ?? 'USD')) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-6 text-center text-neutral-500">{{ __('No transactions in this window.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div>{{ $this->transactions->onEachSide(1)->links() }}</div>
</div>

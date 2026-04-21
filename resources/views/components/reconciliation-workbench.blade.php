<?php

use App\Models\RecurringProjection;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Reconciliation workbench — a single page that surfaces the four classes of
 * orphan financial records and lets the user link or clean them up inline.
 *
 *   1. Unmatched overdue projections — bills expected, no payment seen.
 *   2. Stale pending transactions — manual entries that never cleared.
 *   3. Uncategorised cleared transactions — bank-feed noise waiting for a label.
 *   4. Dangling transfer legs — only one side of a transfer arrived.
 *
 * Each section has inline actions that reuse existing Livewire events
 * (inspector-open / inspector-mark-paid) so behaviour stays consistent
 * with the rest of the app.
 */
new
#[Layout('components.layouts.app', ['title' => 'Reconciliation'])]
class extends Component
{
    #[On('inspector-saved')]
    public function refresh(): void
    {
        // Invalidate all computed queries so the lists reflect the latest state.
        unset(
            $this->overdueProjections,
            $this->stalePending,
            $this->uncategorisedCleared,
            $this->orphanedTransfers,
            $this->counts,
        );
    }

    /** Bills whose due date has passed without a matched payment. */
    #[Computed]
    public function overdueProjections(): EloquentCollection
    {
        return RecurringProjection::query()
            ->with('rule:id,title,account_id,counterparty_contact_id')
            ->whereIn('status', ['projected', 'overdue'])
            ->whereNull('matched_transaction_id')
            ->whereNull('matched_transfer_id')
            ->where('autopay', false)
            ->whereDate('due_on', '<', now()->toDateString())
            ->orderBy('due_on')
            ->limit(50)
            ->get();
    }

    /** Manual transactions marked pending more than a week ago — bank never confirmed. */
    #[Computed]
    public function stalePending(): EloquentCollection
    {
        $cutoff = CarbonImmutable::now()->subDays(7)->toDateString();

        return Transaction::query()
            ->with('account:id,name,currency')
            ->where('status', 'pending')
            ->whereDate('occurred_on', '<', $cutoff)
            ->orderBy('occurred_on')
            ->limit(50)
            ->get();
    }

    /**
     * Cleared bank-feed rows with no category — the user needs to label them
     * so the money radar / budgets can include them in rollups.
     */
    #[Computed]
    public function uncategorisedCleared(): EloquentCollection
    {
        return Transaction::query()
            ->with('account:id,name,currency')
            ->where('status', 'cleared')
            ->whereNull('category_id')
            ->orderByDesc('occurred_on')
            ->limit(50)
            ->get();
    }

    /**
     * Transfer rows still in pending state (bank feeds haven't confirmed both
     * legs). The schema keeps one `status` column for the whole transfer; a
     * "half-done" transfer is one where that status hasn't flipped to
     * cleared after a reasonable grace window.
     */
    #[Computed]
    public function orphanedTransfers(): EloquentCollection
    {
        return Transfer::query()
            ->with(['fromAccount:id,name', 'toAccount:id,name'])
            ->where('status', 'pending')
            ->whereDate('occurred_on', '<', CarbonImmutable::now()->subDays(7)->toDateString())
            ->orderByDesc('occurred_on')
            ->limit(50)
            ->get();
    }

    /**
     * @return array{projections:int, pending:int, uncategorised:int, transfers:int, total:int}
     */
    #[Computed]
    public function counts(): array
    {
        $c = [
            'projections' => $this->overdueProjections->count(),
            'pending' => $this->stalePending->count(),
            'uncategorised' => $this->uncategorisedCleared->count(),
            'transfers' => $this->orphanedTransfers->count(),
        ];
        $c['total'] = array_sum($c);

        return $c;
    }

    /** Flip a stale pending transaction to cleared after user confirmation. */
    public function markCleared(int $transactionId): void
    {
        $t = Transaction::find($transactionId);
        if ($t && $t->status === 'pending') {
            $t->forceFill(['status' => 'cleared'])->save();
            $this->refresh();
        }
    }

    public function deleteTransaction(int $transactionId): void
    {
        Transaction::where('id', $transactionId)->delete();
        $this->refresh();
    }
};
?>

<div class="space-y-5">
    <header>
        <h2 class="text-base font-semibold text-neutral-100">{{ __('Reconciliation workbench') }}</h2>
        <p class="mt-1 text-xs text-neutral-500">
            {{ __('Orphan records that need a decision. Link, categorise, or dismiss — then the radar quiets down.') }}
        </p>
    </header>

    {{-- Inline vendor-ignore editor so filler patterns can be added
         mid-reconcile (e.g. spotting "Purchase authorized on" pollution
         in the uncategorised list) without bouncing to /settings. The
         Re-resolve button inside the editor refreshes existing rows
         to match the updated rules. --}}
    <details class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-4 text-xs">
        <summary class="cursor-pointer text-neutral-300">
            {{ __('Vendor auto-detect · ignore list') }}
            <span class="ml-1 text-neutral-600">{{ __('(add patterns without leaving this page)') }}</span>
        </summary>
        <div class="mt-3">
            <livewire:vendor-ignore-editor />
        </div>
    </details>

    @php($counts = $this->counts)

    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-neutral-800 bg-neutral-900/40 px-4 py-3">
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Unmatched bills') }}</dt>
            <dd class="mt-1 text-xl font-semibold tabular-nums {{ $counts['projections'] ? 'text-rose-300' : 'text-neutral-400' }}">{{ $counts['projections'] }}</dd>
        </div>
        <div class="rounded-xl border border-neutral-800 bg-neutral-900/40 px-4 py-3">
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Stale pending') }}</dt>
            <dd class="mt-1 text-xl font-semibold tabular-nums {{ $counts['pending'] ? 'text-amber-300' : 'text-neutral-400' }}">{{ $counts['pending'] }}</dd>
        </div>
        <div class="rounded-xl border border-neutral-800 bg-neutral-900/40 px-4 py-3">
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Uncategorised') }}</dt>
            <dd class="mt-1 text-xl font-semibold tabular-nums {{ $counts['uncategorised'] ? 'text-amber-300' : 'text-neutral-400' }}">{{ $counts['uncategorised'] }}</dd>
        </div>
        <div class="rounded-xl border border-neutral-800 bg-neutral-900/40 px-4 py-3">
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Half-done transfers') }}</dt>
            <dd class="mt-1 text-xl font-semibold tabular-nums {{ $counts['transfers'] ? 'text-amber-300' : 'text-neutral-400' }}">{{ $counts['transfers'] }}</dd>
        </div>
    </dl>

    @if ($counts['total'] === 0)
        <div class="rounded-xl border border-emerald-800/50 bg-emerald-950/30 px-6 py-10 text-center text-sm text-emerald-200">
            {{ __('Nothing to reconcile. Everything is clean.') }}
        </div>
    @endif

    @if ($this->overdueProjections->isNotEmpty())
        <section aria-labelledby="recon-projections" class="space-y-2">
            <h3 id="recon-projections" class="flex items-baseline justify-between text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                <span>{{ __('Unmatched overdue bills') }}</span>
                <span class="text-rose-400">{{ $this->overdueProjections->count() }}</span>
            </h3>
            <ul class="divide-y divide-neutral-800 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
                @foreach ($this->overdueProjections as $p)
                    <li class="flex items-center justify-between gap-4 px-4 py-2 text-sm">
                        <div class="min-w-0">
                            <div class="truncate text-neutral-100">{{ $p->rule?->title ?? __('Bill') }}</div>
                            <div class="text-[11px] text-neutral-500 tabular-nums">{{ __('due :d', ['d' => Formatting::date($p->due_on)]) }}</div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="shrink-0 tabular-nums text-neutral-400">{{ Formatting::money((float) $p->amount, $p->currency ?? 'USD') }}</span>
                            <button type="button"
                                    wire:click="$dispatch('inspector-mark-paid', { projectionId: {{ $p->id }} })"
                                    class="rounded-md border border-emerald-700/40 bg-emerald-900/20 px-2 py-0.5 text-[10px] uppercase tracking-wider text-emerald-300 hover:bg-emerald-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                {{ __('Mark paid') }}
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($this->stalePending->isNotEmpty())
        <section aria-labelledby="recon-pending" class="space-y-2">
            <h3 id="recon-pending" class="flex items-baseline justify-between text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                <span>{{ __('Stale pending transactions (>7d)') }}</span>
                <span class="text-amber-400">{{ $this->stalePending->count() }}</span>
            </h3>
            <ul class="divide-y divide-neutral-800 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
                @foreach ($this->stalePending as $t)
                    <li class="flex items-center justify-between gap-4 px-4 py-2 text-sm">
                        <div class="min-w-0">
                            <div class="truncate text-neutral-100">{{ $t->description ?: __('(no description)') }}</div>
                            <div class="text-[11px] text-neutral-500 tabular-nums">{{ Formatting::date($t->occurred_on) }} · {{ $t->account?->name }}</div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="shrink-0 tabular-nums {{ (float) $t->amount < 0 ? 'text-neutral-100' : 'text-emerald-400' }}">
                                {{ Formatting::money((float) $t->amount, $t->currency ?? ($t->account?->currency ?? 'USD')) }}
                            </span>
                            <button type="button" wire:click="markCleared({{ $t->id }})"
                                    wire:confirm="{{ __('Flip this transaction to cleared?') }}"
                                    class="rounded-md border border-emerald-700/40 bg-emerald-900/20 px-2 py-0.5 text-[10px] uppercase tracking-wider text-emerald-300 hover:bg-emerald-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                {{ __('Mark cleared') }}
                            </button>
                            <button type="button"
                                    wire:click="$dispatch('inspector-open', { type: 'transaction', id: {{ $t->id }} })"
                                    class="rounded-md border border-neutral-700 bg-neutral-900 px-2 py-0.5 text-[10px] uppercase tracking-wider text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                {{ __('Edit') }}
                            </button>
                            <button type="button" wire:click="deleteTransaction({{ $t->id }})"
                                    wire:confirm="{{ __('Delete this transaction?') }}"
                                    class="rounded-md border border-rose-800/40 bg-rose-950/20 px-2 py-0.5 text-[10px] uppercase tracking-wider text-rose-300 hover:bg-rose-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                {{ __('Delete') }}
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($this->uncategorisedCleared->isNotEmpty())
        <section aria-labelledby="recon-uncat" class="space-y-2">
            <h3 id="recon-uncat" class="flex items-baseline justify-between text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                <span>{{ __('Uncategorised cleared transactions') }}</span>
                <span class="text-amber-400">{{ $this->uncategorisedCleared->count() }}</span>
            </h3>
            <ul class="divide-y divide-neutral-800 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
                @foreach ($this->uncategorisedCleared as $t)
                    <li class="flex items-center justify-between gap-4 px-4 py-2 text-sm">
                        <div class="min-w-0">
                            <div class="truncate text-neutral-100">{{ $t->description ?: __('(no description)') }}</div>
                            <div class="text-[11px] text-neutral-500 tabular-nums">{{ Formatting::date($t->occurred_on) }} · {{ $t->account?->name }}</div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="shrink-0 tabular-nums {{ (float) $t->amount < 0 ? 'text-neutral-100' : 'text-emerald-400' }}">
                                {{ Formatting::money((float) $t->amount, $t->currency ?? ($t->account?->currency ?? 'USD')) }}
                            </span>
                            <button type="button"
                                    wire:click="$dispatch('inspector-open', { type: 'transaction', id: {{ $t->id }} })"
                                    class="rounded-md border border-neutral-700 bg-neutral-900 px-2 py-0.5 text-[10px] uppercase tracking-wider text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                {{ __('Categorise') }}
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($this->orphanedTransfers->isNotEmpty())
        <section aria-labelledby="recon-transfers" class="space-y-2">
            <h3 id="recon-transfers" class="flex items-baseline justify-between text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                <span>{{ __('Half-done transfers') }}</span>
                <span class="text-amber-400">{{ $this->orphanedTransfers->count() }}</span>
            </h3>
            <ul class="divide-y divide-neutral-800 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
                @foreach ($this->orphanedTransfers as $xfer)
                    <li class="flex items-center justify-between gap-4 px-4 py-2 text-sm">
                        <div class="min-w-0">
                            <div class="truncate text-neutral-100">
                                {{ $xfer->fromAccount?->name }} → {{ $xfer->toAccount?->name }}
                            </div>
                            <div class="text-[11px] text-neutral-500 tabular-nums">
                                {{ Formatting::date($xfer->occurred_on) }} · {{ __('pending for >7 days') }}
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="shrink-0 tabular-nums text-neutral-300">
                                {{ Formatting::money((float) $xfer->from_amount, $xfer->from_currency ?? 'USD') }}
                            </span>
                            <button type="button"
                                    wire:click="$dispatch('inspector-open', { type: 'transfer', id: {{ $xfer->id }} })"
                                    class="rounded-md border border-neutral-700 bg-neutral-900 px-2 py-0.5 text-[10px] uppercase tracking-wider text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                {{ __('Edit') }}
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>

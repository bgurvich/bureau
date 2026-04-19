<?php

use App\Models\Contract;
use App\Models\Document;
use App\Models\InventoryItem;
use App\Models\Media;
use App\Models\RecurringProjection;
use App\Models\Task;
use App\Models\Transaction;
use App\Support\Formatting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Weekly review'])]
class extends Component
{
    /** @return Collection<int, Task> */
    #[Computed]
    public function overdueTasks(): Collection
    {
        return Task::query()
            ->whereIn('state', ['open', 'waiting'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->orderBy('priority')
            ->orderBy('due_at')
            ->get(['id', 'title', 'due_at', 'priority']);
    }

    /** @return Collection<int, Transaction> */
    #[Computed]
    public function stalePendingTransactions(): Collection
    {
        return Transaction::query()
            ->where('status', 'pending')
            ->where('occurred_on', '<', now()->subDays(7)->toDateString())
            ->orderBy('occurred_on')
            ->limit(50)
            ->get(['id', 'description', 'amount', 'currency', 'occurred_on']);
    }

    /** @return Collection<int, RecurringProjection> */
    #[Computed]
    public function overdueProjections(): Collection
    {
        return RecurringProjection::with('rule:id,title,account_id')
            ->where('status', 'overdue')
            ->where('autopay', false)
            ->orderBy('due_on')
            ->limit(50)
            ->get();
    }

    /** @return Collection<int, Document> */
    #[Computed]
    public function expiringDocuments(): Collection
    {
        return Document::query()
            ->whereNotNull('expires_on')
            ->whereBetween('expires_on', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->orderBy('expires_on')
            ->get(['id', 'kind', 'label', 'expires_on']);
    }

    /** @return Collection<int, Contract> */
    #[Computed]
    public function expiringContracts(): Collection
    {
        return Contract::query()
            ->whereIn('state', ['active', 'expiring'])
            ->whereNotNull('ends_on')
            ->whereBetween('ends_on', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->orderBy('ends_on')
            ->get(['id', 'title', 'kind', 'ends_on']);
    }

    #[Computed]
    public function unprocessedInventoryCount(): int
    {
        return InventoryItem::whereNull('processed_at')->count();
    }

    #[Computed]
    public function untaggedMediaCount(): int
    {
        return Media::doesntHave('tags')->whereNull('folder_id')->count();
    }

    public function totalAttention(): int
    {
        return $this->overdueTasks->count()
            + $this->stalePendingTransactions->count()
            + $this->overdueProjections->count()
            + $this->expiringDocuments->count()
            + $this->expiringContracts->count();
    }
};
?>

<div class="space-y-5">
    <header>
        <h2 class="text-base font-semibold text-neutral-100">{{ __('Weekly review') }}</h2>
        <p class="mt-1 text-xs text-neutral-500">
            {{ __('Walkthrough of what needs your attention. Click any row to open it; mark things done or reschedule from the Inspector.') }}
        </p>
    </header>

    @if ($this->totalAttention() === 0 && $this->unprocessedInventoryCount === 0 && $this->untaggedMediaCount === 0)
        <div class="rounded-xl border border-dashed border-emerald-900/40 bg-emerald-900/10 p-10 text-center text-sm text-emerald-300">
            {{ __('Inbox zero. Nothing outstanding right now.') }}
        </div>
    @else
        @if ($this->overdueTasks->isNotEmpty())
            <section aria-labelledby="wr-tasks" class="space-y-2">
                <h3 id="wr-tasks" class="flex items-baseline justify-between text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                    <span>{{ __('Overdue tasks') }}</span>
                    <span class="text-rose-400">{{ $this->overdueTasks->count() }}</span>
                </h3>
                <ul class="divide-y divide-neutral-800 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
                    @foreach ($this->overdueTasks as $t)
                        <li>
                            <button type="button"
                                    wire:click="$dispatch('inspector-open', {{ json_encode(['type' => 'task', 'id' => $t->id]) }})"
                                    class="flex w-full items-baseline justify-between gap-3 px-4 py-2 text-left text-sm text-neutral-100 hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                <span class="truncate">P{{ $t->priority }} · {{ $t->title }}</span>
                                <span class="shrink-0 text-[11px] text-rose-400 tabular-nums">{{ Formatting::date($t->due_at) }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($this->stalePendingTransactions->isNotEmpty())
            <section aria-labelledby="wr-pending" class="space-y-2">
                <h3 id="wr-pending" class="flex items-baseline justify-between text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                    <span>{{ __('Stale pending transactions') }}</span>
                    <span class="text-amber-400">{{ $this->stalePendingTransactions->count() }}</span>
                </h3>
                <ul class="divide-y divide-neutral-800 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
                    @foreach ($this->stalePendingTransactions as $t)
                        <li>
                            <button type="button"
                                    wire:click="$dispatch('inspector-open', {{ json_encode(['type' => 'transaction', 'id' => $t->id]) }})"
                                    class="flex w-full items-baseline justify-between gap-3 px-4 py-2 text-left text-sm hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                <span class="truncate text-neutral-100">{{ $t->description ?: __('Transaction') }}</span>
                                <span class="shrink-0 tabular-nums text-neutral-400">{{ number_format((float) $t->amount, 2) }} · {{ Formatting::date($t->occurred_on) }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($this->overdueProjections->isNotEmpty())
            <section aria-labelledby="wr-bills" class="space-y-2">
                <h3 id="wr-bills" class="flex items-baseline justify-between text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                    <span>{{ __('Overdue bills') }}</span>
                    <span class="text-rose-400">{{ $this->overdueProjections->count() }}</span>
                </h3>
                <ul class="divide-y divide-neutral-800 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
                    @foreach ($this->overdueProjections as $p)
                        <li class="flex items-baseline justify-between gap-3 px-4 py-2 text-sm">
                            <span class="truncate text-neutral-100">{{ $p->rule?->title ?? __('Bill') }}</span>
                            <div class="flex items-center gap-3">
                                <span class="shrink-0 tabular-nums text-neutral-400">{{ number_format((float) $p->amount, 2) }} · {{ Formatting::date($p->due_on) }}</span>
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

        @if ($this->expiringDocuments->isNotEmpty())
            <section aria-labelledby="wr-docs" class="space-y-2">
                <h3 id="wr-docs" class="flex items-baseline justify-between text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                    <span>{{ __('Documents expiring ≤ 30d') }}</span>
                    <span class="text-amber-400">{{ $this->expiringDocuments->count() }}</span>
                </h3>
                <ul class="divide-y divide-neutral-800 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
                    @foreach ($this->expiringDocuments as $d)
                        <li>
                            <button type="button"
                                    wire:click="$dispatch('inspector-open', {{ json_encode(['type' => 'document', 'id' => $d->id]) }})"
                                    class="flex w-full items-baseline justify-between gap-3 px-4 py-2 text-left text-sm hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                <span class="truncate text-neutral-100">{{ $d->label ?: ucfirst((string) $d->kind) }}</span>
                                <span class="shrink-0 tabular-nums text-amber-400">{{ Formatting::date($d->expires_on) }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($this->expiringContracts->isNotEmpty())
            <section aria-labelledby="wr-contracts" class="space-y-2">
                <h3 id="wr-contracts" class="flex items-baseline justify-between text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                    <span>{{ __('Contracts ending ≤ 30d') }}</span>
                    <span class="text-amber-400">{{ $this->expiringContracts->count() }}</span>
                </h3>
                <ul class="divide-y divide-neutral-800 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
                    @foreach ($this->expiringContracts as $c)
                        <li>
                            <button type="button"
                                    wire:click="$dispatch('inspector-open', {{ json_encode(['type' => 'contract', 'id' => $c->id]) }})"
                                    class="flex w-full items-baseline justify-between gap-3 px-4 py-2 text-left text-sm hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                <span class="truncate text-neutral-100">{{ $c->title }} <span class="text-neutral-500">· {{ $c->kind }}</span></span>
                                <span class="shrink-0 tabular-nums text-amber-400">{{ Formatting::date($c->ends_on) }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($this->unprocessedInventoryCount > 0 || $this->untaggedMediaCount > 0)
            <section aria-labelledby="wr-backlog" class="space-y-2">
                <h3 id="wr-backlog" class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Capture backlog') }}</h3>
                <div class="grid grid-cols-2 gap-3">
                    @if ($this->unprocessedInventoryCount > 0)
                        <a href="{{ route('assets.inventory', ['status' => 'unprocessed']) }}"
                           class="rounded-lg border border-neutral-800 bg-neutral-900/40 px-4 py-3 text-sm text-neutral-200 hover:bg-neutral-800/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Unprocessed inventory') }}</div>
                            <div class="mt-0.5 tabular-nums text-amber-400">{{ $this->unprocessedInventoryCount }}</div>
                        </a>
                    @endif
                    @if ($this->untaggedMediaCount > 0)
                        <a href="{{ route('records.media') }}"
                           class="rounded-lg border border-neutral-800 bg-neutral-900/40 px-4 py-3 text-sm text-neutral-200 hover:bg-neutral-800/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Untagged media') }}</div>
                            <div class="mt-0.5 tabular-nums text-amber-400">{{ $this->untaggedMediaCount }}</div>
                        </a>
                    @endif
                </div>
            </section>
        @endif
    @endif
</div>

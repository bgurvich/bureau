<?php

use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Support\Formatting;
use App\Support\RruleHumanize;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Bills & Income'])]
class extends Component
{
    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->projections, $this->overdue, $this->rules, $this->groupedProjections);
    }

    #[Computed]
    public function projections(): Collection
    {
        return RecurringProjection::with(['rule:id,title,kind,counterparty_contact_id', 'rule.counterparty:id,display_name'])
            ->whereDate('due_on', '>=', now()->toDateString())
            ->whereDate('due_on', '<=', now()->addDays(90)->toDateString())
            ->whereIn('status', ['projected', 'matched', 'overdue'])
            ->orderBy('due_on')
            ->get();
    }

    #[Computed]
    public function overdue(): Collection
    {
        // Same grace window as attention radar: autopay projections hide for
        // 7 days before they count as "something's wrong".
        $graceCutoff = now()->subDays(7)->toDateString();

        return RecurringProjection::with(['rule:id,title,kind,counterparty_contact_id', 'rule.counterparty:id,display_name'])
            ->where('status', 'overdue')
            ->where(fn ($q) => $q
                ->where('autopay', false)
                ->orWhere(fn ($inner) => $inner
                    ->where('autopay', true)
                    ->where('due_on', '<', $graceCutoff)
                )
            )
            ->orderBy('due_on')
            ->get();
    }

    #[Computed]
    public function rules(): Collection
    {
        return RecurringRule::with(['account:id,name', 'counterparty:id,display_name', 'category:id,name'])
            ->where('active', true)
            ->orderBy('kind')
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function groupedProjections(): array
    {
        $groups = [];
        foreach ($this->projections as $p) {
            $weekStart = $p->due_on->copy()->startOfWeek(\Carbon\CarbonInterface::MONDAY);
            $key = $weekStart->toDateString();
            $groups[$key] ??= [
                'label' => $weekStart->format('M j').' – '.$weekStart->copy()->endOfWeek(\Carbon\CarbonInterface::SUNDAY)->format('M j'),
                'items' => [],
                'net' => 0.0,
            ];
            $groups[$key]['items'][] = $p;
            $groups[$key]['net'] += (float) $p->amount;
        }

        return $groups;
    }
};
?>

<div class="space-y-6">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Bills & Income') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('The next 90 days of recurring obligations, projected from your recurrences.') }}</p>
        </div>
        <x-ui.new-record-button type="bill" :label="__('New bill')" shortcut="B" />
    </header>

    @if ($this->overdue->isNotEmpty())
        <div role="alert" class="rounded-lg border border-amber-800/40 bg-amber-900/20 px-4 py-3 text-sm text-amber-300">
            <div class="flex items-baseline justify-between">
                <strong>{{ __(':count overdue', ['count' => $this->overdue->count()]) }}</strong>
                <span class="text-xs tabular-nums">{{ number_format((float) $this->overdue->sum('amount'), 2) }}</span>
            </div>
            <ul class="mt-2 space-y-1 text-xs text-amber-200/80">
                @foreach ($this->overdue->take(5) as $o)
                    <li class="flex justify-between gap-4">
                        <span>{{ Formatting::date($o->due_on) }} · {{ $o->rule?->title ?? '—' }}</span>
                        <span class="tabular-nums">{{ number_format((float) $o->amount, 2) }}</span>
                    </li>
                @endforeach
                @if ($this->overdue->count() > 5)
                    <li class="text-[11px] text-amber-200/60">{{ __('+:n more', ['n' => $this->overdue->count() - 5]) }}</li>
                @endif
            </ul>
        </div>
    @endif

    <section aria-labelledby="upcoming-heading" class="space-y-4">
        <h3 id="upcoming-heading" class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Upcoming') }}</h3>

        @if (empty($this->groupedProjections))
            <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
                {{ __('Nothing on the horizon.') }}
            </div>
        @else
            @foreach ($this->groupedProjections as $group)
                <div class="overflow-hidden rounded-lg border border-neutral-800 bg-neutral-900/40">
                    <div class="flex items-baseline justify-between border-b border-neutral-800/60 bg-neutral-900/60 px-4 py-2">
                        <div class="text-xs font-medium text-neutral-200">{{ $group['label'] }}</div>
                        <div class="text-xs tabular-nums {{ $group['net'] >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                            {{ ($group['net'] >= 0 ? '+' : '').number_format($group['net'], 2) }}
                        </div>
                    </div>
                    <ul class="divide-y divide-neutral-800/60">
                        @foreach ($group['items'] as $p)
                            <li class="flex items-center justify-between gap-4 px-4 py-2 text-sm {{ $p->autopay && $p->status !== 'matched' ? 'opacity-70' : '' }}">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-xs text-neutral-500 tabular-nums">{{ Formatting::date($p->due_on) }}</span>
                                        <span class="truncate {{ $p->status === 'matched' ? 'text-neutral-400 line-through' : 'text-neutral-100' }}">{{ $p->rule?->title ?? '—' }}</span>
                                    </div>
                                    <div class="text-[11px] text-neutral-500">
                                        {{ ucfirst($p->rule?->kind ?? '—') }}
                                        @if ($p->rule?->counterparty)
                                            · {{ $p->rule->counterparty->display_name }}
                                        @endif
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <div class="text-right">
                                        <div class="text-sm tabular-nums {{ (float) $p->amount >= 0 ? 'text-emerald-400' : 'text-neutral-100' }}">
                                            {{ number_format((float) $p->amount, 2) }}
                                        </div>
                                        <div class="flex items-center justify-end gap-1 text-[10px] uppercase tracking-wider">
                                            @if ($p->status === 'matched')
                                                @if ($p->matched_transaction_id)
                                                    <button type="button"
                                                            wire:click="$dispatch('inspector-open', {{ json_encode(['type' => 'transaction', 'id' => $p->matched_transaction_id]) }})"
                                                            title="{{ __('Open matched transaction') }}"
                                                            class="rounded bg-emerald-900/30 px-1.5 py-0.5 text-emerald-400 hover:bg-emerald-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                                        {{ __('paid') }}
                                                    </button>
                                                @else
                                                    <span class="rounded bg-emerald-900/30 px-1.5 py-0.5 text-emerald-400">{{ __('paid') }}</span>
                                                @endif
                                            @endif
                                            @if ($p->autopay)
                                                <span class="rounded bg-neutral-800 px-1.5 py-0.5 text-neutral-400">{{ __('auto') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    @if ($p->status !== 'matched' && ! $p->autopay)
                                        <button type="button"
                                                wire:click="$dispatch('inspector-mark-paid', { projectionId: {{ $p->id }} })"
                                                class="rounded-md border border-emerald-700/40 bg-emerald-900/20 px-2 py-1 text-[11px] uppercase tracking-wider text-emerald-300 hover:bg-emerald-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                            {{ __('Mark paid') }}
                                        </button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        @endif
    </section>

    <section aria-labelledby="rules-heading" class="space-y-3">
        <h3 id="rules-heading" class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Recurrence catalog') }}</h3>

        @if ($this->rules->isEmpty())
            <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-6 text-center text-sm text-neutral-500">
                {{ __('No active recurrences yet.') }}
            </div>
        @else
            <ul class="divide-y divide-neutral-800 rounded-lg border border-neutral-800 bg-neutral-900/40">
                @foreach ($this->rules as $r)
                    <li>
                        <button type="button"
                                @if ($r->kind === 'bill')
                                    wire:click="$dispatch('inspector-open', { type: 'bill', id: {{ $r->id }} })"
                                @endif
                                class="flex w-full items-center justify-between px-4 py-2 text-left text-sm transition hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 text-neutral-100">
                                    <span class="truncate">{{ $r->title }}</span>
                                    @if ($r->autopay)
                                        <span class="rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ __('auto') }}</span>
                                    @endif
                                </div>
                                <div class="text-[11px] text-neutral-500">
                                    {{ ucfirst($r->kind) }} · {{ RruleHumanize::describe($r->rrule, $r->dtstart) }}
                                    @if ($r->account) · {{ $r->account->name }} @endif
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                @if ($r->amount !== null)
                                    <div class="text-sm tabular-nums {{ (float) $r->amount >= 0 ? 'text-emerald-400' : 'text-neutral-100' }}">
                                        {{ number_format((float) $r->amount, 2) }} {{ $r->currency }}
                                    </div>
                                @endif
                            </div>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>

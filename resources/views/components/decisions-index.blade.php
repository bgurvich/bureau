<?php

use App\Models\Decision;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Decisions'])]
class extends Component
{
    /** '' | 'pending' | 'awaiting_followup' | 'resolved' */
    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->decisions, $this->statusCounts);
    }

    /** @return Collection<int, Decision> */
    #[Computed]
    public function decisions(): Collection
    {
        /** @var Collection<int, Decision> $list */
        $list = Decision::query()
            ->with('user:id,name')
            ->when($this->statusFilter === 'pending', fn ($q) => $q->whereNull('outcome'))
            ->when($this->statusFilter === 'awaiting_followup', fn ($q) => $q
                ->whereNull('outcome')
                ->whereNotNull('follow_up_on')
                ->where('follow_up_on', '<=', now()->toDateString()))
            ->when($this->statusFilter === 'resolved', fn ($q) => $q->whereNotNull('outcome'))
            ->orderByDesc('decided_on')
            ->orderByDesc('id')
            ->get();

        return $list;
    }

    /**
     * Chip counts at the top — one per filter bucket. Unfiltered
     * (this->decisions in default mode) doesn't help here because each
     * chip needs its own independent count, so the numbers come from
     * dedicated scalar queries.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        $today = now()->toDateString();

        return [
            'pending' => Decision::whereNull('outcome')->count(),
            'awaiting_followup' => Decision::whereNull('outcome')
                ->whereNotNull('follow_up_on')
                ->where('follow_up_on', '<=', $today)
                ->count(),
            'resolved' => Decision::whereNotNull('outcome')->count(),
        ];
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Decisions')"
        :description="__('Choices worth remembering. Capture the context + rationale so future-you has the full story.')">
        <x-ui.new-record-button type="decision" :label="__('New decision')" />
    </x-ui.page-header>

    <div class="flex flex-wrap gap-3 text-sm">
        @foreach ([
            'pending' => __('Pending'),
            'awaiting_followup' => __('Follow-up due'),
            'resolved' => __('Resolved'),
        ] as $v => $l)
            @php
                $count = $this->statusCounts[$v] ?? 0;
                $active = $this->statusFilter === $v;
            @endphp
            <button type="button"
                    wire:click="$set('statusFilter', '{{ $active ? '' : $v }}')"
                    class="rounded-md border px-2 py-1 text-xs tabular-nums focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'border-neutral-400 bg-neutral-800 text-neutral-100' : 'border-neutral-700 bg-neutral-900 text-neutral-400 hover:border-neutral-600 hover:text-neutral-200' }}">
                {{ $l }} <span class="ml-1 text-neutral-500">· {{ $count }}</span>
            </button>
        @endforeach
    </div>

    @if ($this->decisions->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No decisions logged yet.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->decisions as $d)
                @php
                    $isResolved = $d->outcome !== null && $d->outcome !== '';
                    $followDue = ! $isResolved && $d->follow_up_on && $d->follow_up_on->isPast();
                    $followDays = $d->follow_up_on
                        ? (int) now()->startOfDay()->diffInDays($d->follow_up_on, absolute: false)
                        : null;
                @endphp
                <x-ui.inspector-row type="decision" :id="$d->id" :label="$d->title" class="flex items-start gap-4 px-4 py-3 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <span class="w-20 shrink-0 tabular-nums text-xs text-neutral-500">{{ \App\Support\Formatting::date($d->decided_on) }}</span>
                            <span class="font-medium text-neutral-100 {{ $isResolved ? 'line-through opacity-70' : '' }}">{{ $d->title }}</span>
                        </div>
                        @if ($d->chosen || $d->rationale)
                            <div class="mt-0.5 ml-20 text-[11px] text-neutral-400">
                                @if ($d->chosen)<span class="font-medium text-neutral-300">{{ $d->chosen }}</span>@endif
                                @if ($d->chosen && $d->rationale) — @endif
                                @if ($d->rationale)<span>{{ \Illuminate\Support\Str::limit($d->rationale, 140) }}</span>@endif
                            </div>
                        @endif
                        <div class="mt-0.5 ml-20 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                            @if ($isResolved)
                                <span class="text-emerald-400">{{ __('resolved') }}</span>
                            @elseif ($followDue)
                                <span class="text-rose-400">{{ __('follow-up due') }} ({{ \App\Support\Formatting::date($d->follow_up_on) }})</span>
                            @elseif ($d->follow_up_on)
                                <span class="text-amber-400">{{ __('follow up :on', ['on' => \App\Support\Formatting::date($d->follow_up_on)]) }} ({{ $followDays }}d)</span>
                            @else
                                <span>{{ __('open') }}</span>
                            @endif
                            @if ($d->user)<span>— {{ $d->user->name }}</span>@endif
                        </div>
                    </div>
                </x-ui.inspector-row>
            @endforeach
        </ul>
    @endif
</div>

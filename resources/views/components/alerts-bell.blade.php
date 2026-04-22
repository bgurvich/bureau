<?php

use App\Models\PetCheckup;
use App\Models\PetVaccination;
use App\Models\RecurringProjection;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\Transaction;
use App\Support\Birthdays;
use App\Support\Formatting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function overdueTasks(): Collection
    {
        return Task::where('state', 'open')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->orderBy('due_at')
            ->limit(5)
            ->get(['id', 'title', 'due_at']);
    }

    #[Computed]
    public function overdueBills(): Collection
    {
        // Skip matched projections; autopay ones stay quiet for 7 days before
        // surfacing (assume the charge posts; longer = something failed).
        $graceCutoff = now()->subDays(7)->toDateString();

        return RecurringProjection::with('rule:id,title,currency')
            ->where('status', 'overdue')
            ->where(fn ($q) => $q
                ->where('autopay', false)
                ->orWhere(fn ($inner) => $inner
                    ->where('autopay', true)
                    ->where('due_on', '<', $graceCutoff)
                )
            )
            ->orderBy('due_on')
            ->limit(5)
            ->get(['id', 'rule_id', 'due_on', 'amount', 'currency']);
    }

    #[Computed]
    public function pendingReminders(): Collection
    {
        return Reminder::where('state', 'pending')
            ->where('remind_at', '<=', now())
            ->orderBy('remind_at')
            ->limit(5)
            ->get(['id', 'body', 'remind_at']);
    }

    #[Computed]
    public function unreconciledCount(): int
    {
        return Transaction::where('status', 'pending')->count();
    }

    /**
     * Next ≤ 5 birthdays within the coming week — small window so the
     * bell stays about *acute* items. Everything 7-30 days out belongs
     * on the time radar, not here.
     *
     * @return BaseCollection<int, \App\Models\Contact>
     */
    #[Computed]
    public function upcomingBirthdays(): BaseCollection
    {
        return Birthdays::upcoming(7)->take(5);
    }

    /**
     * Pet vaccinations expiring in the next 14 days (bigger window than
     * birthdays because booking a vet slot takes time) + anything
     * already expired. Sorted oldest-due-first so the most urgent is up
     * top. ≤ 5 rows.
     *
     * @return Collection<int, PetVaccination>
     */
    #[Computed]
    public function expiringVaccinations(): Collection
    {
        return PetVaccination::query()
            ->with('pet:id,name,species')
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', now()->addDays(14)->toDateString())
            ->orderBy('valid_until')
            ->limit(5)
            ->get(['id', 'pet_id', 'vaccine_name', 'valid_until']);
    }

    /**
     * Pet checkups whose next_due_on has passed. Upcoming-but-not-yet-
     * overdue are left for the time radar to pick up — this is the
     * "you missed it" bucket.
     *
     * @return Collection<int, PetCheckup>
     */
    #[Computed]
    public function overduePetCheckups(): Collection
    {
        return PetCheckup::query()
            ->with('pet:id,name,species')
            ->whereNotNull('next_due_on')
            ->where('next_due_on', '<', now()->toDateString())
            ->orderBy('next_due_on')
            ->limit(5)
            ->get(['id', 'pet_id', 'kind', 'next_due_on']);
    }

    #[Computed]
    public function total(): int
    {
        return $this->overdueTasks->count()
            + $this->overdueBills->count()
            + $this->pendingReminders->count()
            + $this->upcomingBirthdays->count()
            + $this->expiringVaccinations->count()
            + $this->overduePetCheckups->count()
            + ($this->unreconciledCount > 0 ? 1 : 0);
    }
};
?>

<div x-data="{
        open: false,
        items() { return [...this.$el.querySelectorAll('[data-alert-item]')]; },
        focusFirst() { this.$nextTick(() => this.items()[0]?.focus()); },
        move(delta) {
            const items = this.items();
            if (items.length === 0) return;
            const active = document.activeElement;
            const idx = items.indexOf(active);
            const next = idx === -1 ? 0 : (idx + delta + items.length) % items.length;
            items[next]?.focus();
        },
     }"
     @keydown.escape.window="open = false"
     @click.outside="open = false"
     @alerts-open.window="open = true; focusFirst()"
     @keydown.arrow-down.prevent="if (open) move(1)"
     @keydown.arrow-up.prevent="if (open) move(-1)"
     class="relative">
    <button type="button"
            @click="open = !open; if (open) focusFirst()"
            :aria-expanded="open.toString()"
            aria-haspopup="menu"
            aria-label="{{ __('Alerts') }}"
            class="relative flex h-8 w-8 items-center justify-center rounded-md border border-neutral-800 bg-neutral-900 text-neutral-300 hover:border-neutral-700 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"
             stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 8a6 6 0 1 1 12 0c0 7 3 7 3 10H3c0-3 3-3 3-10Z"/>
            <path d="M10 21a2 2 0 0 0 4 0"/>
        </svg>
        @if ($this->total > 0)
            <span aria-hidden="true"
                  class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] font-bold leading-none text-neutral-950 tabular-nums">
                {{ $this->total > 99 ? '99+' : $this->total }}
            </span>
        @endif
    </button>

    <div
        x-cloak
        x-show="open"
        x-transition.opacity.duration.100ms
        role="menu"
        aria-label="{{ __('Alerts') }}"
        class="absolute right-0 z-30 mt-2 w-80 overflow-hidden rounded-md border border-neutral-800 bg-neutral-900 shadow-xl"
    >
        <header class="border-b border-neutral-800 px-4 py-2.5">
            <div class="flex items-baseline justify-between">
                <span class="text-sm font-medium text-neutral-100">{{ __('Needs attention') }}</span>
                <span class="text-[11px] text-neutral-500 tabular-nums">{{ $this->total }}</span>
            </div>
        </header>

        <div class="max-h-96 overflow-y-auto">
            @if ($this->total === 0)
                <div class="px-4 py-8 text-center text-sm text-neutral-500">
                    {{ __('Nothing is waiting on you.') }}
                </div>
            @endif

            @if ($this->overdueTasks->count())
                <section class="border-b border-neutral-800/60 py-2">
                    <h3 class="px-4 pb-1 text-[10px] uppercase tracking-wider text-rose-400">{{ __('Overdue tasks') }}</h3>
                    <ul>
                        @foreach ($this->overdueTasks as $t)
                            <li>
                                <button type="button"
                                        data-alert-item
                                        @click="open = false"
                                        wire:click="$dispatch('inspector-open', { type: 'task', id: {{ $t->id }} })"
                                        class="flex w-full items-baseline justify-between gap-3 px-4 py-1.5 text-left text-sm hover:bg-neutral-800 focus:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    <span class="truncate text-neutral-200">{{ $t->title }}</span>
                                    <span class="shrink-0 text-[11px] text-neutral-500 tabular-nums">{{ Formatting::date($t->due_at) }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($this->overdueBills->count())
                <section class="border-b border-neutral-800/60 py-2">
                    <h3 class="px-4 pb-1 text-[10px] uppercase tracking-wider text-rose-400">{{ __('Overdue bills') }}</h3>
                    <ul>
                        @foreach ($this->overdueBills as $b)
                            <li>
                                <a href="{{ route('fiscal.recurring') }}"
                                   data-alert-item
                                   class="flex items-baseline justify-between gap-3 px-4 py-1.5 text-sm hover:bg-neutral-800 focus:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    <span class="truncate text-neutral-200">{{ $b->rule?->title ?? '—' }}</span>
                                    <span class="shrink-0 text-[11px] tabular-nums text-rose-400">
                                        {{ Formatting::money((float) $b->amount, $b->rule?->currency ?? 'USD') }}
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($this->pendingReminders->count())
                <section class="border-b border-neutral-800/60 py-2">
                    <h3 class="px-4 pb-1 text-[10px] uppercase tracking-wider text-amber-400">{{ __('Due reminders') }}</h3>
                    <ul>
                        @foreach ($this->pendingReminders as $r)
                            <li class="flex items-baseline justify-between gap-3 px-4 py-1.5 text-sm">
                                <span class="truncate text-neutral-200">{{ $r->body ?? '—' }}</span>
                                <span class="shrink-0 text-[11px] text-neutral-500 tabular-nums">{{ Formatting::datetime($r->remind_at) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($this->upcomingBirthdays->count())
                <section class="border-b border-neutral-800/60 py-2">
                    <h3 class="px-4 pb-1 text-[10px] uppercase tracking-wider text-fuchsia-300">{{ __('Upcoming birthdays') }}</h3>
                    <ul>
                        @foreach ($this->upcomingBirthdays as $c)
                            @php($nextBday = $c->getAttribute('_next_birthday'))
                            @php($age = $c->birthday ? \App\Support\Birthdays::ageOn($c->birthday, $nextBday) : null)
                            <li>
                                <button type="button"
                                        wire:click="$dispatch('inspector-open', { type: 'contact', id: {{ $c->id }} })"
                                        data-alert-item
                                        class="flex w-full items-baseline justify-between gap-3 px-4 py-1.5 text-sm hover:bg-neutral-800 focus:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    <span class="truncate text-neutral-200">
                                        {{ $c->display_name }}
                                        @if ($age !== null)
                                            <span class="ml-1 text-[11px] text-neutral-500">{{ __('turns :n', ['n' => $age]) }}</span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 text-[11px] text-fuchsia-300 tabular-nums">{{ Formatting::date($nextBday) }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($this->expiringVaccinations->count() || $this->overduePetCheckups->count())
                <section class="border-b border-neutral-800/60 py-2">
                    <h3 class="px-4 pb-1 text-[10px] uppercase tracking-wider text-sky-300">{{ __('Pet care') }}</h3>
                    <ul>
                        @foreach ($this->expiringVaccinations as $v)
                            @php($expired = $v->valid_until && $v->valid_until->lessThan(now()->startOfDay()))
                            <li>
                                <button type="button"
                                        wire:click="$dispatch('subentity-edit-open', { type: 'pet_vaccination', id: {{ $v->id }} })"
                                        data-alert-item
                                        class="flex w-full items-baseline justify-between gap-3 px-4 py-1.5 text-sm hover:bg-neutral-800 focus:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    <span class="truncate text-neutral-200">
                                        {{ $v->pet?->name ?? __('(pet)') }}
                                        <span class="ml-1 text-[11px] text-neutral-500">{{ $v->vaccine_name }}</span>
                                    </span>
                                    <span class="shrink-0 text-[11px] {{ $expired ? 'text-rose-300' : 'text-sky-300' }} tabular-nums">
                                        {{ $expired ? __('expired') : __('expires') }} {{ Formatting::date($v->valid_until) }}
                                    </span>
                                </button>
                            </li>
                        @endforeach
                        @foreach ($this->overduePetCheckups as $c)
                            <li>
                                <button type="button"
                                        wire:click="$dispatch('subentity-edit-open', { type: 'pet_checkup', id: {{ $c->id }} })"
                                        data-alert-item
                                        class="flex w-full items-baseline justify-between gap-3 px-4 py-1.5 text-sm hover:bg-neutral-800 focus:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    <span class="truncate text-neutral-200">
                                        {{ $c->pet?->name ?? __('(pet)') }}
                                        <span class="ml-1 text-[11px] text-neutral-500">{{ str_replace('_', ' ', $c->kind) }}</span>
                                    </span>
                                    <span class="shrink-0 text-[11px] text-rose-300 tabular-nums">
                                        {{ __('overdue') }} {{ Formatting::date($c->next_due_on) }}
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($this->unreconciledCount > 0)
                <section class="py-2">
                    <a href="{{ route('fiscal.transactions', ['status' => 'pending']) }}"
                       data-alert-item
                       class="flex items-baseline justify-between gap-3 px-4 py-1.5 text-sm hover:bg-neutral-800 focus:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <span class="text-neutral-200">{{ __('Unreconciled transactions') }}</span>
                        <span class="shrink-0 text-[11px] text-neutral-400 tabular-nums">{{ $this->unreconciledCount }}</span>
                    </a>
                </section>
            @endif
        </div>
    </div>
</div>

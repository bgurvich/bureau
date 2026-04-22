<?php

use App\Models\Contact;
use App\Support\Birthdays;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * Widen window on the radar vs the alerts-bell's 7-day acute surface —
     * the dashboard view is "what's on the horizon this month or two",
     * not "what's urgent today". Kept deliberately distinct so both
     * surfaces carry their own signal.
     */
    private const HORIZON_DAYS = 45;

    #[Computed]
    public function contactsCount(): int
    {
        return Contact::query()->count();
    }

    /** @return Collection<int, Contact> */
    #[Computed]
    public function upcoming(): Collection
    {
        return Birthdays::upcoming(self::HORIZON_DAYS);
    }

    /** Today's birthdays (same-day bucket within the upcoming list). */
    #[Computed]
    public function todays(): Collection
    {
        $today = now()->startOfDay();

        return $this->upcoming->filter(fn (Contact $c) => $c->getAttribute('_next_birthday')?->equalTo($today))->values();
    }
};
?>

<div class="rounded-xl border border-neutral-800 bg-neutral-900/50 p-5">
    <div class="mb-4 flex items-baseline justify-between">
        <h3 class="text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __('Relationships') }}</h3>
        <a href="{{ route('relationships.contacts') }}" class="text-xs text-neutral-500 hover:text-neutral-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">{{ __('All →') }}</a>
    </div>

    <div class="grid grid-cols-3 gap-4">
        <div>
            <div class="text-xs text-neutral-500">{{ __('Contacts') }}</div>
            <div class="mt-1 text-xl font-semibold tabular-nums text-neutral-100">{{ $this->contactsCount }}</div>
        </div>
        <div>
            <div class="text-xs text-neutral-500">{{ __('Birthdays ≤ 45d') }}</div>
            <div class="mt-1 text-xl font-semibold tabular-nums {{ $this->upcoming->isNotEmpty() ? 'text-violet-300' : 'text-neutral-400' }}">{{ $this->upcoming->count() }}</div>
        </div>
        <div>
            <div class="text-xs text-neutral-500">{{ __('Today') }}</div>
            <div class="mt-1 text-xl font-semibold tabular-nums {{ $this->todays->isNotEmpty() ? 'text-fuchsia-400' : 'text-neutral-400' }}">{{ $this->todays->count() }}</div>
        </div>
    </div>

    @if ($this->upcoming->isNotEmpty())
        <ul class="mt-4 space-y-1.5 border-t border-neutral-800 pt-3 text-sm">
            @foreach ($this->upcoming->take(5) as $contact)
                @php
                    $next = $contact->getAttribute('_next_birthday');
                    $age = Birthdays::ageOn($contact->birthday, $next);
                    $isToday = $next && $next->equalTo(now()->startOfDay());
                @endphp
                <li class="flex items-baseline justify-between gap-3">
                    <div class="flex min-w-0 items-baseline gap-2">
                        <span class="shrink-0 text-xs uppercase tracking-wider {{ $isToday ? 'text-fuchsia-400' : 'text-violet-400' }}">
                            {{ $isToday ? __('today') : $next?->format('M j') }}
                        </span>
                        <span class="truncate text-neutral-200">{{ $contact->display_name }}</span>
                        @if ($age !== null)
                            <span class="shrink-0 text-[11px] text-neutral-500">
                                {{ __('turns :n', ['n' => $age + ($isToday ? 0 : 1)]) }}
                            </span>
                        @endif
                    </div>
                    <span class="shrink-0 text-xs tabular-nums text-neutral-500">
                        {{ $next?->diffForHumans(['parts' => 1, 'short' => true]) }}
                    </span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-4 text-center text-xs text-neutral-600">{{ __('No birthdays in the next 45 days.') }}</p>
    @endif
</div>

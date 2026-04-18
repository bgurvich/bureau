<?php

use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Support\Formatting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Bookkeeper export'])]
class extends Component
{
    public string $from = '';

    public string $to = '';

    public string $lockThrough = '';

    public string $lockReason = '';

    public ?string $lockMessage = null;

    public function mount(): void
    {
        // Default: previous calendar month.
        $this->from = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $this->to = now()->subMonthNoOverflow()->endOfMonth()->toDateString();
    }

    #[Computed]
    public function activeLock(): ?PeriodLock
    {
        return PeriodLock::query()
            ->whereNull('unlocked_at')
            ->orderByDesc('locked_through')
            ->first();
    }

    #[Computed]
    public function txnCount(): int
    {
        if ($this->from === '' || $this->to === '') {
            return 0;
        }

        return Transaction::query()
            ->whereDate('occurred_on', '>=', $this->from)
            ->whereDate('occurred_on', '<=', $this->to)
            ->count();
    }

    public function lockPeriod(): void
    {
        $data = $this->validate([
            'lockThrough' => ['required', 'date'],
            'lockReason' => ['nullable', 'string', 'max:255'],
        ]);

        PeriodLock::create([
            'locked_through' => $data['lockThrough'],
            'reason' => $data['lockReason'] ?: null,
            'locked_by_user_id' => auth()->id(),
            'locked_at' => now(),
        ]);

        $this->reset(['lockThrough', 'lockReason']);
        $this->lockMessage = __('Locked through :date.', ['date' => Formatting::date($data['lockThrough'])]);
        unset($this->activeLock);
    }

    public function unlock(): void
    {
        $lock = $this->activeLock;
        if ($lock) {
            $lock->update(['unlocked_at' => now()]);
            $this->lockMessage = __('Lock released.');
            unset($this->activeLock);
        }
    }
};
?>

<div class="space-y-6 max-w-3xl">
    <header>
        <h2 class="text-base font-semibold text-neutral-100">{{ __('Bookkeeper export') }}</h2>
        <p class="mt-1 text-xs text-neutral-500">
            {{ __('Download a zipped package for an external bookkeeper or CPA: chart of accounts, categories, vendors/customers, transactions, and transfers for the chosen period.') }}
        </p>
    </header>

    @if ($lockMessage)
        <div role="status" class="rounded-md border border-emerald-800/40 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-300">
            {{ $lockMessage }}
        </div>
    @endif

    <section aria-labelledby="export-heading" class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-6">
        <h3 id="export-heading" class="text-sm font-medium text-neutral-200">{{ __('Export a period') }}</h3>
        <p class="mt-1 text-xs text-neutral-500">{{ __('The zip includes a README describing the schema.') }}</p>

        <form method="POST" action="{{ route('bookkeeper.export') }}" class="mt-4 flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label for="bk-from" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('From') }}</label>
                <input wire:model.live="from" name="from" id="bk-from" type="date" required
                       class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            </div>
            <div>
                <label for="bk-to" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('To') }}</label>
                <input wire:model.live="to" name="to" id="bk-to" type="date" required
                       class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            </div>
            <div class="text-xs text-neutral-500">
                <div class="text-[10px] uppercase tracking-wider">{{ __('Transactions in range') }}</div>
                <div class="mt-1 tabular-nums text-neutral-200">{{ number_format($this->txnCount) }}</div>
            </div>
            <button type="submit"
                    class="ml-auto rounded-md bg-neutral-100 px-4 py-2 text-sm font-medium text-neutral-900 transition hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ __('Download zip') }}
            </button>
        </form>
    </section>

    <section aria-labelledby="lock-heading" class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-6">
        <h3 id="lock-heading" class="text-sm font-medium text-neutral-200">{{ __('Period lock') }}</h3>
        <p class="mt-1 text-xs text-neutral-500">
            {{ __('After your CPA files, lock the period so no transaction dated on or before that day can be edited.') }}
        </p>

        @if ($this->activeLock)
            <div class="mt-4 flex items-center justify-between rounded-md border border-amber-800/40 bg-amber-900/15 px-4 py-3 text-sm">
                <div>
                    <div class="font-medium text-amber-300">
                        {{ __('Locked through :date', ['date' => Formatting::date($this->activeLock->locked_through)]) }}
                    </div>
                    @if ($this->activeLock->reason)
                        <div class="mt-0.5 text-xs text-amber-200/70">{{ $this->activeLock->reason }}</div>
                    @endif
                </div>
                <button wire:click="unlock"
                        wire:confirm="{{ __('Release the period lock?') }}"
                        class="rounded-md border border-amber-700/50 px-3 py-1.5 text-xs text-amber-200 hover:bg-amber-900/30">
                    {{ __('Unlock') }}
                </button>
            </div>
        @else
            <form wire:submit="lockPeriod" class="mt-4 flex flex-wrap items-end gap-3" novalidate>
                <div>
                    <label for="lk-date" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Lock through') }}</label>
                    <input wire:model="lockThrough" id="lk-date" type="date" required
                           class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    @error('lockThrough')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
                </div>
                <div class="flex-1 min-w-[200px]">
                    <label for="lk-reason" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Reason') }}</label>
                    <input wire:model="lockReason" id="lk-reason" type="text" placeholder="{{ __('Filed with CPA, tax year 2025…') }}"
                           class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                </div>
                <button type="submit"
                        class="rounded-md border border-amber-700/40 bg-amber-900/20 px-4 py-2 text-sm font-medium text-amber-200 hover:bg-amber-900/40">
                    {{ __('Lock period') }}
                </button>
            </form>
        @endif
    </section>
</div>

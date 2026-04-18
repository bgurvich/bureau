<?php

use App\Models\OnlineAccount;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Online accounts'])]
class extends Component
{
    #[Url(as: 'kind')]
    public string $kindFilter = '';

    #[Url(as: 'tier')]
    public string $tierFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public bool $inCaseOfOnly = false;

    #[On('inspector-saved')]
    public function refresh(string $type = ''): void
    {
        if ($type === 'online_account') {
            unset($this->items, $this->tierCounts);
        }
    }

    /** @return Collection<int, OnlineAccount> */
    #[Computed]
    public function items(): Collection
    {
        return OnlineAccount::query()
            ->with(['recoveryContact:id,display_name', 'linkedContract:id,title'])
            ->where(fn ($q) => $q->where('user_id', auth()->id())->orWhereNull('user_id'))
            ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter))
            ->when($this->tierFilter !== '', fn ($q) => $q->where('importance_tier', $this->tierFilter))
            ->when($this->inCaseOfOnly, fn ($q) => $q->where('in_case_of_pack', true))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('service_name', 'like', $term)
                    ->orWhere('login_email', 'like', $term)
                    ->orWhere('username', 'like', $term)
                    ->orWhere('url', 'like', $term)
                );
            })
            ->orderByRaw("CASE importance_tier WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('kind')
            ->orderBy('service_name')
            ->limit(500)
            ->get();
    }

    /** @return array<string, int> */
    #[Computed]
    public function tierCounts(): array
    {
        $counts = OnlineAccount::query()
            ->where(fn ($q) => $q->where('user_id', auth()->id())->orWhereNull('user_id'))
            ->selectRaw('importance_tier, count(*) as n')
            ->groupBy('importance_tier')
            ->pluck('n', 'importance_tier')
            ->all();

        return [
            'critical' => (int) ($counts['critical'] ?? 0),
            'high' => (int) ($counts['high'] ?? 0),
            'medium' => (int) ($counts['medium'] ?? 0),
            'low' => (int) ($counts['low'] ?? 0),
        ];
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Online accounts') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Digital footprint — where each account lives and how to recover it. Not a password vault.') }}</p>
        </div>
        <x-ui.new-record-button type="online_account" :label="__('New online account')" shortcut="O" />
    </header>

    <dl class="flex gap-5 text-xs">
        <div>
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Critical') }}</dt>
            <dd class="mt-0.5 tabular-nums {{ $this->tierCounts['critical'] > 0 ? 'text-rose-400' : 'text-neutral-500' }}">{{ $this->tierCounts['critical'] }}</dd>
        </div>
        <div>
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('High') }}</dt>
            <dd class="mt-0.5 tabular-nums text-amber-400">{{ $this->tierCounts['high'] }}</dd>
        </div>
        <div>
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Medium') }}</dt>
            <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->tierCounts['medium'] }}</dd>
        </div>
        <div>
            <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Low') }}</dt>
            <dd class="mt-0.5 tabular-nums text-neutral-500">{{ $this->tierCounts['low'] }}</dd>
        </div>
    </dl>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="oa-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="oa-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Service, email, URL…') }}">
        </div>
        <div>
            <label for="oa-kind" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Type') }}</label>
            <select wire:model.live="kindFilter" id="oa-kind"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (App\Support\Enums::onlineAccountKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="oa-tier" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Importance') }}</label>
            <select wire:model.live="tierFilter" id="oa-tier"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (App\Support\Enums::importanceTiers() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <label class="flex items-center gap-2 text-xs text-neutral-300">
            <input wire:model.live="inCaseOfOnly" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('In case of pack') }}
        </label>
    </form>

    @if ($this->items->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No online accounts match those filters.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->items as $o)
                @php
                    $tierClass = match ($o->importance_tier) {
                        'critical' => 'bg-rose-900/40 text-rose-300',
                        'high' => 'bg-amber-900/40 text-amber-300',
                        'low' => 'bg-neutral-800 text-neutral-500',
                        default => 'bg-neutral-800 text-neutral-400',
                    };
                @endphp
                <li>
                    <button type="button"
                            wire:click="$dispatch('inspector-open', { type: 'online_account', id: {{ $o->id }} })"
                            class="flex w-full items-start gap-4 px-4 py-3 text-left text-sm transition hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2">
                                <span class="truncate text-neutral-100">{{ $o->service_name }}</span>
                                <span class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ App\Support\Enums::onlineAccountKinds()[$o->kind] ?? $o->kind }}</span>
                                <span class="shrink-0 rounded px-1.5 py-0.5 text-[10px] uppercase tracking-wider {{ $tierClass }}">{{ $o->importance_tier }}</span>
                                @if ($o->in_case_of_pack)
                                    <span aria-label="{{ __('In case of pack') }}" class="shrink-0 rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">ICO</span>
                                @endif
                            </div>
                            <div class="mt-0.5 flex flex-wrap gap-3 text-[11px] text-neutral-500">
                                @if ($o->login_email)
                                    <span class="tabular-nums">{{ $o->login_email }}</span>
                                @elseif ($o->username)
                                    <span class="tabular-nums">{{ $o->username }}</span>
                                @endif
                                @if ($o->url)
                                    <span class="truncate font-mono">{{ $o->url }}</span>
                                @endif
                                @if ($o->mfa_method && $o->mfa_method !== 'none')
                                    <span>{{ App\Support\Enums::mfaMethods()[$o->mfa_method] ?? $o->mfa_method }}</span>
                                @endif
                                @if ($o->linkedContract)
                                    <span class="text-neutral-400">↳ {{ $o->linkedContract->title }}</span>
                                @endif
                            </div>
                        </div>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>

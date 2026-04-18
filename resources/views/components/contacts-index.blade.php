<?php

use App\Models\Contact;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Contacts'])]
class extends Component
{
    #[Url(as: 'kind')]
    public string $kindFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public bool $favoritesOnly = false;

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->contacts);
    }

    #[Computed]
    public function contacts(): Collection
    {
        return Contact::query()
            ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter))
            ->when($this->favoritesOnly, fn ($q) => $q->where('favorite', true))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('display_name', 'like', $term)
                    ->orWhere('organization', 'like', $term)
                    ->orWhere('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                );
            })
            ->orderByDesc('favorite')
            ->orderBy('display_name')
            ->limit(500)
            ->get();
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Contacts') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('People and organizations you deal with.') }}</p>
        </div>
        <x-ui.new-record-button type="contact" :label="__('New contact')" shortcut="C" />
    </header>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="c-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="c-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Name or organization…') }}">
        </div>
        <div>
            <label for="c-kind" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Kind') }}</label>
            <select wire:model.live="kindFilter" id="c-kind"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (App\Support\Enums::contactKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <label class="flex items-center gap-2 text-xs text-neutral-300">
            <input wire:model.live="favoritesOnly" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Favorites only') }}
        </label>
    </form>

    @if ($this->contacts->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No contacts yet.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->contacts as $c)
                <li>
                    <button type="button"
                            wire:click="$dispatch('inspector-open', { type: 'contact', id: {{ $c->id }} })"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <span aria-hidden="true" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-neutral-800 text-xs text-neutral-300">
                            {{ strtoupper(mb_substr($c->display_name, 0, 1)) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2">
                                <span class="truncate text-sm text-neutral-100">{{ $c->display_name }}</span>
                                @if ($c->favorite)
                                    <span aria-label="{{ __('Favorite') }}" class="text-amber-400">★</span>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-2 text-[11px] text-neutral-500">
                                <span class="uppercase tracking-wider">{{ $c->kind }}</span>
                                @if ($c->is_vendor)
                                    <span class="rounded bg-neutral-800 px-1.5 text-neutral-400">{{ __('Vendor') }}</span>
                                @endif
                                @if ($c->is_customer)
                                    <span class="rounded bg-neutral-800 px-1.5 text-neutral-400">{{ __('Customer') }}</span>
                                @endif
                                @if ($c->organization)
                                    <span>{{ $c->organization }}</span>
                                @endif
                                @if (is_array($c->emails) && count($c->emails))
                                    <span>{{ $c->emails[0] }}</span>
                                @endif
                            </div>
                        </div>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>

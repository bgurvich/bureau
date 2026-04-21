<?php

use App\Models\PhysicalMail;
use App\Support\Formatting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Post'])]
class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'kind')]
    public string $kindFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public bool $actionOnly = false;

    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->mail);
    }

    #[Computed]
    public function mail(): Collection
    {
        return PhysicalMail::query()
            ->with(['sender:id,display_name', 'media' => fn ($q) => $q->where('mime', 'like', 'image/%')])
            ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter))
            ->when($this->statusFilter === 'unprocessed', fn ($q) => $q->whereNull('processed_at'))
            ->when($this->statusFilter === 'processed', fn ($q) => $q->whereNotNull('processed_at'))
            ->when($this->actionOnly, fn ($q) => $q->where('action_required', true))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('subject', 'like', $term)
                    ->orWhere('summary', 'like', $term)
                );
            })
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Post') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">
                {{ __('Physical mail received — letters, bills, slips. Snap a photo from the phone, file the details here.') }}
            </p>
        </div>
        <x-ui.new-record-button type="physical_mail" :label="__('New post')" shortcut="P" />
    </header>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="pm-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="pm-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Subject or summary…') }}">
        </div>
        <div>
            <label for="pm-kind" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Kind') }}</label>
            <select wire:model.live="kindFilter" id="pm-kind"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                @foreach (App\Support\Enums::physicalMailKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="pm-status" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Status') }}</label>
            <select wire:model.live="statusFilter" id="pm-status"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('Any') }}</option>
                <option value="unprocessed">{{ __('Unprocessed') }}</option>
                <option value="processed">{{ __('Processed') }}</option>
            </select>
        </div>
        <label class="flex items-center gap-2 text-xs text-neutral-300">
            <input wire:model.live="actionOnly" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Action required') }}
        </label>
    </form>

    @if ($this->mail->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No mail filed yet.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->mail as $m)
                @php($thumb = $m->media->first())
                <li wire:key="pm-row-{{ $m->id }}">
                    <button type="button"
                            wire:click="$dispatch('inspector-open', { type: 'physical_mail', id: {{ $m->id }} })"
                            class="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-neutral-800/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        @if ($thumb)
                            <img src="{{ route('media.thumb', $thumb) }}"
                                 alt=""
                                 loading="lazy"
                                 class="h-12 w-12 shrink-0 rounded-md border border-neutral-800 object-cover">
                        @else
                            <div aria-hidden="true" class="h-12 w-12 shrink-0 rounded-md border border-dashed border-neutral-800/60"></div>
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2">
                                <span class="truncate text-sm text-neutral-100">
                                    {{ $m->subject ?: ($m->sender?->display_name ?? __('(no subject)')) }}
                                </span>
                                @if ($m->action_required && $m->processed_at === null)
                                    <span class="rounded bg-amber-900/40 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-amber-300">{{ __('action') }}</span>
                                @endif
                                @if ($m->processed_at)
                                    <span class="rounded bg-neutral-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ __('processed') }}</span>
                                @endif
                            </div>
                            <div class="mt-0.5 flex flex-wrap gap-2 text-[11px] text-neutral-500">
                                <span class="uppercase tracking-wider">{{ $m->kind }}</span>
                                <span class="tabular-nums">{{ Formatting::date($m->received_on) }}</span>
                                @if ($m->sender)
                                    <span>{{ $m->sender->display_name }}</span>
                                @endif
                            </div>
                            @if ($m->summary)
                                <p class="mt-1 truncate text-xs text-neutral-400">{{ $m->summary }}</p>
                            @endif
                        </div>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>

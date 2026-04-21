<?php

use App\Models\Account;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Document;
use App\Models\InventoryItem;
use App\Models\Media;
use App\Models\Note;
use App\Models\Property;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\Vehicle;
use App\Support\Formatting;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $open = false;

    public string $query = '';

    public int $active = 0;

    #[On('global-search-open')]
    public function openSearch(): void
    {
        $this->query = '';
        $this->active = 0;
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->query = '';
        $this->active = 0;
    }

    public function updatedQuery(): void
    {
        $this->active = 0;
    }

    public function selectResult(int $index): void
    {
        $results = $this->results;
        if (! isset($results[$index])) {
            return;
        }

        $row = $results[$index];
        $this->open = false;

        if (($row['inspector'] ?? false) && isset($row['type'], $row['id'])) {
            $this->dispatch('inspector-open', type: $row['type'], id: $row['id']);

            return;
        }

        if (isset($row['url'])) {
            $this->redirect($row['url'], navigate: true);
        }
    }

    public function moveActive(int $delta): void
    {
        $count = count($this->results);
        if ($count === 0) {
            return;
        }
        $this->active = max(0, min($count - 1, $this->active + $delta));
    }

    /**
     * @return array<int, array{type?:string,id?:int,title:string,subtitle:string,group:string,inspector?:bool,url?:string}>
     */
    #[Computed]
    public function results(): array
    {
        $q = trim($this->query);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $out = [];

        foreach (Note::query()
            ->where(fn ($b) => $b->where('title', 'like', $like)->orWhere('body', 'like', $like))
            ->orderByDesc('updated_at')->limit(5)->get(['id', 'title', 'body']) as $n) {
            $out[] = [
                'type' => 'note', 'id' => (int) $n->id,
                'title' => $n->title ?: Str::limit((string) $n->body, 60),
                'subtitle' => $n->title ? Str::limit((string) $n->body, 80) : '',
                'group' => __('Notes'),
                'inspector' => true,
            ];
        }

        foreach (Task::query()
            ->where(fn ($b) => $b->where('title', 'like', $like)->orWhere('description', 'like', $like))
            ->orderByRaw('CASE WHEN state = ? THEN 0 ELSE 1 END', ['open'])
            ->orderBy('priority')
            ->limit(5)->get(['id', 'title', 'description', 'priority', 'state']) as $t) {
            $out[] = [
                'type' => 'task', 'id' => (int) $t->id,
                'title' => (string) $t->title,
                'subtitle' => $t->description
                    ? Str::limit((string) $t->description, 80)
                    : 'P'.$t->priority.' · '.$t->state,
                'group' => __('Tasks'),
                'inspector' => true,
            ];
        }

        foreach (Contact::query()
            ->where(fn ($b) => $b->where('display_name', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('organization', 'like', $like))
            ->orderByDesc('favorite')->orderBy('display_name')
            ->limit(5)->get(['id', 'display_name', 'kind', 'organization']) as $c) {
            $out[] = [
                'type' => 'contact', 'id' => (int) $c->id,
                'title' => (string) $c->display_name,
                'subtitle' => trim(($c->kind ?? '').($c->organization ? ' · '.$c->organization : '')),
                'group' => __('Contacts'),
                'inspector' => true,
            ];
        }

        foreach (Transaction::query()
            ->where(fn ($b) => $b->where('description', 'like', $like)
                ->orWhere('reference_number', 'like', $like)
                ->orWhereRaw('CAST(amount AS CHAR) LIKE ?', [$like]))
            ->orderByDesc('occurred_on')
            ->limit(5)->get(['id', 'description', 'amount', 'currency', 'occurred_on', 'reference_number']) as $t) {
            $out[] = [
                'type' => 'transaction', 'id' => (int) $t->id,
                'title' => $t->description ?: ($t->reference_number ?? __('Transaction')),
                'subtitle' => $t->occurred_on?->toDateString().' · '.Formatting::money((float) $t->amount, $t->currency ?? 'USD'),
                'group' => __('Transactions'),
                'inspector' => true,
            ];
        }

        foreach (Subscription::query()
            ->where('state', 'active')
            ->where('name', 'like', $like)
            ->orderBy('name')
            ->limit(5)->get(['id', 'name', 'monthly_cost_cached', 'currency']) as $s) {
            $out[] = [
                'type' => 'subscription', 'id' => (int) $s->id,
                'title' => (string) $s->name,
                'subtitle' => $s->monthly_cost_cached
                    ? Formatting::money((float) $s->monthly_cost_cached, $s->currency ?? 'USD').'/mo'
                    : '',
                'group' => __('Subscriptions'),
                'inspector' => true,
            ];
        }

        // View-only surfaces: jump to drill-down / stub page.
        foreach (Contract::query()
            ->where('title', 'like', $like)
            ->limit(3)->get(['id', 'title', 'kind']) as $c) {
            $out[] = [
                'title' => (string) $c->title,
                'subtitle' => (string) $c->kind,
                'group' => __('Contracts'),
                'url' => route('relationships.contracts'),
            ];
        }

        foreach (Document::query()
            ->where(fn ($b) => $b->where('label', 'like', $like)->orWhere('number', 'like', $like))
            ->limit(3)->get(['id', 'label', 'kind']) as $d) {
            $out[] = [
                'title' => $d->label ?: (string) $d->kind,
                'subtitle' => (string) $d->kind,
                'group' => __('Documents'),
                'url' => route('records.documents'),
            ];
        }

        foreach (Account::query()
            ->where(fn ($b) => $b->where('name', 'like', $like)->orWhere('institution', 'like', $like))
            ->limit(3)->get(['id', 'name', 'type', 'institution']) as $a) {
            $out[] = [
                'title' => (string) $a->name,
                'subtitle' => trim($a->type.($a->institution ? ' · '.$a->institution : '')),
                'group' => __('Accounts'),
                'url' => route('fiscal.accounts'),
            ];
        }

        foreach (Vehicle::query()
            ->where(fn ($b) => $b->where('make', 'like', $like)
                ->orWhere('model', 'like', $like)
                ->orWhere('license_plate', 'like', $like)
                ->orWhere('vin', 'like', $like))
            ->limit(3)->get(['id', 'make', 'model', 'year', 'license_plate']) as $v) {
            $out[] = [
                'type' => 'vehicle', 'id' => (int) $v->id,
                'title' => trim(($v->year ? $v->year.' ' : '').($v->make ?? '').' '.($v->model ?? '')),
                'subtitle' => (string) ($v->license_plate ?? ''),
                'group' => __('Vehicles'),
                'inspector' => true,
            ];
        }

        foreach (Property::query()
            ->where('name', 'like', $like)
            ->limit(3)->get(['id', 'name', 'kind']) as $p) {
            $out[] = [
                'type' => 'property', 'id' => (int) $p->id,
                'title' => (string) $p->name,
                'subtitle' => (string) $p->kind,
                'group' => __('Properties'),
                'inspector' => true,
            ];
        }

        foreach (InventoryItem::query()
            ->where(fn ($b) => $b->where('name', 'like', $like)
                ->orWhere('brand', 'like', $like)
                ->orWhere('model_number', 'like', $like)
                ->orWhere('serial_number', 'like', $like))
            ->limit(3)->get(['id', 'name', 'brand', 'category']) as $i) {
            $out[] = [
                'type' => 'inventory', 'id' => (int) $i->id,
                'title' => (string) $i->name,
                'subtitle' => trim(($i->brand ? $i->brand.' · ' : '').($i->category ?? '')),
                'group' => __('Inventory'),
                'inspector' => true,
            ];
        }

        // Media — matches filename AND OCR-extracted text from scanned receipts
        // and bills. Jump to /media with ?focus={id} to auto-open the preview.
        foreach (Media::query()
            ->where(fn ($b) => $b->where('original_name', 'like', $like)
                ->orWhere('ocr_text', 'like', $like))
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->limit(5)->get(['id', 'original_name', 'ocr_text', 'mime']) as $m) {
            $snippet = '';
            if ($m->ocr_text) {
                $pos = mb_stripos((string) $m->ocr_text, $q);
                $start = max(0, $pos !== false ? $pos - 30 : 0);
                $snippet = mb_substr((string) $m->ocr_text, $start, 120);
                if ($start > 0) {
                    $snippet = '… '.$snippet;
                }
            }
            $out[] = [
                'title' => $m->original_name ?: __('Media').' #'.$m->id,
                'subtitle' => trim($snippet ?: (string) $m->mime),
                'group' => __('Media'),
                'url' => route('records.media', ['focus' => $m->id]),
            ];
        }

        return $out;
    }
};
?>

<div
    x-data="{
        open: @entangle('open').live,
        get active() { return $wire.active },
        init() {
            this.$watch('open', o => {
                if (o) {
                    this.$nextTick(() => this.$refs.input?.focus());
                }
            });
        },
    }"
    @keydown.escape.window="if (open) $wire.close()"
    {{-- .prevent was the whole problem: Alpine runs it BEFORE the
         inner guard, so every `/` key got preventDefault()'d even
         while typing in a textarea (vendor ignore list, note body,
         etc.). Inlined the preventDefault inside the guard so it
         fires only when we actually handle the shortcut. --}}
    @keydown.slash.window="
        if (open) return;
        const el = document.activeElement;
        if (el && (['INPUT','TEXTAREA','SELECT'].includes(el.tagName) || el.isContentEditable)) return;
        $event.preventDefault();
        Livewire.dispatch('global-search-open');
    "
>
    <div x-show="open" x-cloak x-transition.opacity class="fixed inset-0 z-40 bg-black/70" aria-hidden="true"
         @click="$wire.close()"></div>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         role="dialog" aria-modal="true" aria-label="{{ __('Global search') }}"
         class="fixed left-1/2 top-24 z-50 w-full max-w-xl -translate-x-1/2 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-950 shadow-2xl">
        <div class="flex items-center gap-2 border-b border-neutral-800 px-4 py-3">
            <svg class="h-4 w-4 text-neutral-500" viewBox="0 0 24 24" fill="none" aria-hidden="true"
                 stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="7"/>
                <path d="m20 20-4-4"/>
            </svg>
            <input x-ref="input"
                   wire:model.live.debounce.150ms="query"
                   type="text"
                   placeholder="{{ __('Search notes, tasks, contacts, transactions…') }}"
                   aria-label="{{ __('Search') }}"
                   autocomplete="off"
                   @keydown.arrow-down.prevent="$wire.moveActive(1)"
                   @keydown.arrow-up.prevent="$wire.moveActive(-1)"
                   @keydown.enter.prevent="$wire.selectResult($wire.active)"
                   class="flex-1 bg-transparent text-sm text-neutral-100 placeholder-neutral-500 focus:outline-none">
            <kbd class="rounded border border-neutral-800 bg-neutral-900 px-1.5 py-0.5 font-mono text-[10px] text-neutral-500">ESC</kbd>
        </div>

        <div class="max-h-96 overflow-y-auto">
            @php
                $results = $this->results;
                $lastGroup = null;
            @endphp

            @if (mb_strlen(trim($query)) < 2)
                <div class="px-4 py-8 text-center text-sm text-neutral-500">
                    {{ __('Type at least two characters to search.') }}
                </div>
            @elseif (empty($results))
                <div class="px-4 py-8 text-center text-sm text-neutral-500">
                    {{ __('No matches.') }}
                </div>
            @else
                @foreach ($results as $idx => $row)
                    @if ($row['group'] !== $lastGroup)
                        @php $lastGroup = $row['group']; @endphp
                        <div class="border-t border-neutral-900 px-4 py-1.5 text-[10px] uppercase tracking-wider text-neutral-500 first:border-t-0">
                            {{ $row['group'] }}
                        </div>
                    @endif
                    <button type="button"
                            wire:click="selectResult({{ $idx }})"
                            @mouseenter="$wire.set('active', {{ $idx }}, false)"
                            data-active="{{ $active === $idx ? 'true' : 'false' }}"
                            class="flex w-full items-baseline justify-between gap-3 px-4 py-2 text-left text-sm transition
                                   {{ $active === $idx ? 'bg-neutral-800' : 'hover:bg-neutral-800/60' }}
                                   focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-neutral-100">{{ $row['title'] }}</div>
                            @if ($row['subtitle'])
                                <div class="truncate text-[11px] text-neutral-500">{{ $row['subtitle'] }}</div>
                            @endif
                        </div>
                        @if (($row['inspector'] ?? false))
                            <kbd class="shrink-0 rounded border border-neutral-700 bg-neutral-900 px-1.5 py-0.5 font-mono text-[10px] text-neutral-500">{{ __('Edit') }}</kbd>
                        @else
                            <kbd class="shrink-0 rounded border border-neutral-700 bg-neutral-900 px-1.5 py-0.5 font-mono text-[10px] text-neutral-500">{{ __('Open') }}</kbd>
                        @endif
                    </button>
                @endforeach
            @endif
        </div>

        <div class="flex items-center justify-between border-t border-neutral-800 bg-neutral-900/50 px-4 py-2 text-[11px] text-neutral-500">
            <div class="flex items-center gap-3">
                <span><kbd class="rounded border border-neutral-800 bg-neutral-950 px-1 py-0.5 font-mono">↑↓</kbd> {{ __('navigate') }}</span>
                <span><kbd class="rounded border border-neutral-800 bg-neutral-950 px-1 py-0.5 font-mono">↵</kbd> {{ __('select') }}</span>
            </div>
            <span><kbd class="rounded border border-neutral-800 bg-neutral-950 px-1 py-0.5 font-mono">/</kbd> {{ __('to open') }}</span>
        </div>
    </div>
</div>

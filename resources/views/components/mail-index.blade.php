<?php

use App\Models\Contract;
use App\Models\Document;
use App\Models\MailMessage;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Support\Formatting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Mail'])]
class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'source')]
    public string $sourceFilter = '';

    #[Computed]
    public function messages(): Collection
    {
        return MailMessage::query()
            ->with([
                'integration:id,provider,label',
                'inbox:id,local_address',
                'attachments' => fn ($q) => $q->with('media:id,mime,ocr_status'),
            ])
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('from_address', 'like', $term)
                    ->orWhere('from_name', 'like', $term)
                    ->orWhere('subject', 'like', $term)
                );
            })
            ->when($this->sourceFilter === 'postmark', fn ($q) => $q->whereNotNull('inbox_id'))
            ->when($this->sourceFilter === 'jmap', fn ($q) => $q->whereHas(
                'integration',
                fn ($i) => $i->where('provider', 'jmap_fastmail')
            ))
            ->when($this->sourceFilter === 'gmail', fn ($q) => $q->whereHas(
                'integration',
                fn ($i) => $i->where('provider', 'gmail')
            ))
            ->orderByDesc('received_at')
            ->limit(200)
            ->get();
    }

    #[Computed]
    public function counts(): array
    {
        return [
            'total' => MailMessage::count(),
            'with_attachment' => MailMessage::has('attachments')->count(),
            'last_24h' => MailMessage::where('received_at', '>=', now()->subDay())->count(),
        ];
    }

    /**
     * message_id → array of linked records (kind, label, inspector-type, id),
     * derived through the existing attachments → media → mediables chain.
     * Computed once per page render so the row template reads from a map
     * instead of running N+1 queries.
     *
     * @return array<int, array<int, array{kind: string, label: string, inspector_type: string, id: int}>>
     */
    #[Computed]
    public function processedRecordsByMessage(): array
    {
        $out = [];
        $mediaIds = $this->messages->flatMap(
            fn ($m) => $m->attachments->pluck('media_id')->filter()
        )->unique()->values()->all();

        if ($mediaIds === []) {
            foreach ($this->messages as $m) {
                $out[$m->id] = [];
            }

            return $out;
        }

        $rows = DB::table('mediables')
            ->whereIn('media_id', $mediaIds)
            ->where('role', 'receipt')
            ->get(['media_id', 'mediable_type', 'mediable_id']);

        // media_id → array of {kind,label,inspector_type,id}
        $byMedia = [];
        foreach ($rows->groupBy('mediable_type') as $type => $group) {
            $class = Relation::getMorphedModel((string) $type) ?? (string) $type;
            if (! class_exists($class)) {
                continue;
            }
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $class */
            $byId = $class::query()
                ->whereIn('id', $group->pluck('mediable_id')->map(fn ($v) => (int) $v)->all())
                ->get()
                ->keyBy('id');
            foreach ($group as $row) {
                $rec = $byId->get((int) $row->mediable_id);
                if (! $rec) {
                    continue;
                }
                $byMedia[(int) $row->media_id][] = [
                    'kind' => $this->kindLabelForRecord($rec),
                    'label' => $this->displayLabelForRecord($rec),
                    'inspector_type' => $this->inspectorTypeForRecord($rec),
                    'id' => $rec->id,
                ];
            }
        }

        foreach ($this->messages as $m) {
            $records = [];
            foreach ($m->attachments as $a) {
                if ($a->media_id && isset($byMedia[$a->media_id])) {
                    foreach ($byMedia[$a->media_id] as $rec) {
                        $records[] = $rec;
                    }
                }
            }
            $out[$m->id] = $records;
        }

        return $out;
    }

    private function kindLabelForRecord(\Illuminate\Database\Eloquent\Model $r): string
    {
        return match (get_class($r)) {
            RecurringRule::class => __('Bill'),
            Transaction::class => __('Transaction'),
            Contract::class => __('Contract'),
            Document::class => __('Document'),
            default => class_basename($r),
        };
    }

    private function displayLabelForRecord(\Illuminate\Database\Eloquent\Model $r): string
    {
        return match (get_class($r)) {
            RecurringRule::class, Contract::class => (string) ($r->title ?? '#'.$r->id),
            Transaction::class => Formatting::money((float) ($r->amount ?? 0), $r->currency ?? 'USD').' '.($r->description ?? ''),
            Document::class => (string) ($r->label ?? $r->kind ?? '#'.$r->id),
            default => '#'.$r->id,
        };
    }

    private function inspectorTypeForRecord(\Illuminate\Database\Eloquent\Model $r): string
    {
        return match (get_class($r)) {
            RecurringRule::class => 'bill',
            Transaction::class => 'transaction',
            Contract::class => 'contract',
            Document::class => 'document',
            default => '',
        };
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Mail') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Emails ingested from Postmark, Fastmail, and Gmail. Attachments land in :m.', ['m' => 'Media']) }}</p>
        </div>
        <dl class="flex gap-5 text-right text-xs">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Total') }}</dt>
                <dd class="tabular-nums text-neutral-200">{{ $this->counts['total'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('With scan') }}</dt>
                <dd class="tabular-nums text-neutral-200">{{ $this->counts['with_attachment'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Last 24h') }}</dt>
                <dd class="tabular-nums text-neutral-200">{{ $this->counts['last_24h'] }}</dd>
            </div>
        </dl>
    </header>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="mail-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="mail-q" type="text"
                   class="mt-1 w-64 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('From, subject…') }}">
        </div>
        <div>
            <label for="mail-src" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Source') }}</label>
            <select wire:model.live="sourceFilter" id="mail-src"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All sources') }}</option>
                <option value="postmark">Postmark</option>
                <option value="jmap">Fastmail</option>
                <option value="gmail">Gmail</option>
            </select>
        </div>
    </form>

    @if ($this->messages->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No mail ingested yet. Configure Postmark, Fastmail, or Gmail in Integrations.') }}
        </div>
    @else
        <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40">
            @foreach ($this->messages as $m)
                @php($firstMedia = $m->attachments->firstWhere(fn ($a) => $a->media && str_starts_with((string) $a->media->mime, 'image/'))?->media)
                @php($firstAttachment = $m->attachments->firstWhere(fn ($a) => $a->media !== null)?->media)
                <li class="flex items-start gap-3 px-4 py-3 text-sm">
                    @if ($firstMedia)
                        <a href="{{ route('records.media', ['focus' => $firstMedia->id]) }}"
                           title="{{ __('Open scan') }}"
                           aria-label="{{ __('Open scan') }}"
                           class="block h-10 w-10 shrink-0 overflow-hidden rounded border border-neutral-800 bg-neutral-950 hover:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <img src="{{ route('media.file', $firstMedia) }}" alt="" loading="lazy"
                                 class="h-full w-full object-cover opacity-80 hover:opacity-100" />
                        </a>
                    @else
                        <span aria-hidden="true" class="block h-10 w-10 shrink-0 rounded border border-dashed border-neutral-800/60"></span>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate text-neutral-100">{{ $m->subject ?: __('(no subject)') }}</div>
                                <div class="truncate text-[11px] text-neutral-500">
                                    {{ $m->from_name ?: $m->from_address ?: '—' }}
                                    @if ($m->from_name && $m->from_address)
                                        <span class="text-neutral-600">· {{ $m->from_address }}</span>
                                    @endif
                                </div>
                            </div>
                            <span class="shrink-0 text-[11px] tabular-nums text-neutral-500">
                                {{ Formatting::datetime($m->received_at) }}
                            </span>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-[10px] uppercase tracking-wider">
                            @if ($m->processed_at)
                                <span class="rounded bg-emerald-900/30 px-1.5 py-0.5 text-emerald-300">{{ __('processed') }}</span>
                            @endif
                            @if ($m->attachments->isNotEmpty())
                                <span class="rounded bg-neutral-800 px-1.5 py-0.5 text-neutral-400">
                                    {{ trans_choice(':n attachment|:n attachments', $m->attachments->count(), ['n' => $m->attachments->count()]) }}
                                </span>
                            @endif
                            @if ($m->integration)
                                <span class="rounded bg-neutral-800 px-1.5 py-0.5 text-neutral-500">{{ $m->integration->provider }}</span>
                            @elseif ($m->inbox_id)
                                <span class="rounded bg-neutral-800 px-1.5 py-0.5 text-neutral-500">postmark</span>
                            @endif
                        </div>
                        @php($links = $this->processedRecordsByMessage[$m->id] ?? [])
                        @if ($links !== [])
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($links as $rec)
                                    @if ($rec['inspector_type'] !== '')
                                        <button type="button"
                                                wire:click="$dispatch('inspector-open', {{ json_encode(['type' => $rec['inspector_type'], 'id' => $rec['id']]) }})"
                                                class="flex items-center gap-1 rounded-md border border-emerald-800/50 bg-emerald-950/30 px-2 py-0.5 text-[11px] text-emerald-100 hover:bg-emerald-950/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                            <span class="rounded bg-emerald-900/60 px-1 py-px text-[10px] uppercase tracking-wider text-emerald-200">{{ $rec['kind'] }}</span>
                                            <span class="truncate">{{ Str::limit($rec['label'], 40) }}</span>
                                        </button>
                                    @else
                                        <span class="flex items-center gap-1 rounded-md border border-neutral-800 bg-neutral-900/40 px-2 py-0.5 text-[11px] text-neutral-300">
                                            <span class="rounded bg-neutral-800 px-1 py-px text-[10px] uppercase tracking-wider text-neutral-500">{{ $rec['kind'] }}</span>
                                            <span class="truncate">{{ Str::limit($rec['label'], 40) }}</span>
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        @elseif ($firstAttachment)
                            {{-- Unlinked attachment: offer one-click promotion to Bill or Transaction.
                                 Opens the Inspector with the media id so the existing OCR prefill
                                 pipeline fills counterparty / amount / dates automatically when
                                 Tier 2 OCR has written Media::ocr_extracted. Picks the first media
                                 attachment (image OR pdf) — receipts often come as PDF. --}}
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <button type="button"
                                        wire:click="$dispatch('inspector-open', { type: 'bill', mediaId: {{ $firstAttachment->id }} })"
                                        class="flex items-center gap-1 rounded-md border border-neutral-700 bg-neutral-900 px-2 py-0.5 text-[11px] text-neutral-200 hover:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    <span class="text-neutral-500">+</span> {{ __('Create bill') }}
                                </button>
                                <button type="button"
                                        wire:click="$dispatch('inspector-open', { type: 'transaction', mediaId: {{ $firstAttachment->id }} })"
                                        class="flex items-center gap-1 rounded-md border border-neutral-700 bg-neutral-900 px-2 py-0.5 text-[11px] text-neutral-200 hover:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    <span class="text-neutral-500">+</span> {{ __('Create transaction') }}
                                </button>
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>

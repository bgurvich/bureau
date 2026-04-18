<?php

use App\Jobs\OcrMedia;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\Tag;
use App\Support\FileSize;
use App\Support\Formatting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Media'])]
class extends Component
{
    #[Url(as: 'mime')]
    public string $mimeFilter = '';

    #[Url(as: 'folder')]
    public string $folderFilter = '';

    #[Url(as: 'ocr')]
    public string $ocrFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $previewId = null;

    public string $draft_original_name = '';

    public ?int $draft_folder_id = null;

    public string $draft_captured_at = '';

    public string $draft_tag_list = '';

    public function openPreview(int $id): void
    {
        $m = Media::with('tags:id,name')->find($id);
        if (! $m) {
            return;
        }
        $this->previewId = $m->id;
        $this->draft_original_name = $m->original_name ?? '';
        $this->draft_folder_id = $m->folder_id;
        $this->draft_captured_at = $m->captured_at?->format('Y-m-d\TH:i') ?? '';
        $this->draft_tag_list = $m->tags->pluck('name')->implode(' ');
    }

    public function closePreview(): void
    {
        $this->previewId = null;
        $this->reset(['draft_original_name', 'draft_folder_id', 'draft_captured_at', 'draft_tag_list']);
    }

    public function saveMetadata(): void
    {
        $m = Media::find($this->previewId);
        if (! $m) {
            return;
        }

        $this->validate([
            'draft_original_name' => 'nullable|string|max:255',
            'draft_folder_id' => 'nullable|integer|exists:media_folders,id',
            'draft_captured_at' => 'nullable|date',
        ]);

        $m->forceFill([
            'original_name' => trim($this->draft_original_name) ?: null,
            'folder_id' => $this->draft_folder_id ?: null,
            'captured_at' => $this->draft_captured_at ? \Carbon\CarbonImmutable::parse($this->draft_captured_at) : null,
        ])->save();

        // sync tags via HasTags
        $names = $this->parseTagList($this->draft_tag_list);
        $ids = [];
        foreach ($names as $name) {
            $tag = Tag::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name]
            );
            $ids[] = $tag->id;
        }
        $m->tags()->sync($ids);

        unset($this->media, $this->counts);
    }

    public function retryOcr(): void
    {
        $m = Media::find($this->previewId);
        if (! $m) {
            return;
        }
        $m->forceFill(['ocr_status' => 'pending'])->save();
        OcrMedia::dispatch($m->id);
        unset($this->media);
    }

    public function deleteMedia(): void
    {
        $m = Media::find($this->previewId);
        if (! $m) {
            return;
        }

        try {
            Storage::disk($m->disk ?: 'local')->delete($m->path);
        } catch (\Throwable $e) {
            // File already gone or disk misconfigured — still remove the row.
        }

        // cascadeOnDelete on `mediables` cleans pivots automatically
        $m->delete();

        $this->closePreview();
        unset($this->media, $this->counts);
    }

    /**
     * @return array<int, string>
     */
    private function parseTagList(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $names = [];
        foreach ($parts as $p) {
            $name = trim(ltrim(trim($p), '#'));
            if ($name === '') {
                continue;
            }
            $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    #[Computed]
    public function currentMedia(): ?Media
    {
        return $this->previewId ? Media::with('folder:id,label,path', 'tags:id,name')->find($this->previewId) : null;
    }

    #[Computed]
    public function media(): Collection
    {
        return Media::query()
            ->with('folder:id,label,path')
            ->when($this->mimeFilter === 'image', fn ($q) => $q->where('mime', 'like', 'image/%'))
            ->when($this->mimeFilter === 'pdf', fn ($q) => $q->where('mime', 'application/pdf'))
            ->when($this->mimeFilter === 'other', fn ($q) => $q
                ->where(fn ($w) => $w
                    ->whereNull('mime')
                    ->orWhere(fn ($inner) => $inner
                        ->where('mime', 'not like', 'image/%')
                        ->where('mime', '!=', 'application/pdf')
                    )
                )
            )
            ->when($this->folderFilter !== '', fn ($q) => $q->where('folder_id', $this->folderFilter))
            ->when($this->ocrFilter !== '', fn ($q) => $q->where('ocr_status', $this->ocrFilter))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($inner) => $inner
                    ->where('original_name', 'like', $term)
                    ->orWhere('ocr_text', 'like', $term)
                );
            })
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    #[Computed]
    public function counts(): array
    {
        return [
            'total' => Media::count(),
            'total_bytes' => (int) Media::sum('size'),
            'images' => Media::where('mime', 'like', 'image/%')->count(),
            'pdfs' => Media::where('mime', 'application/pdf')->count(),
            'ocr_pending' => Media::where('ocr_status', 'pending')->count(),
        ];
    }

    /** @return Collection<int, MediaFolder> */
    #[Computed]
    public function folders(): Collection
    {
        return MediaFolder::orderBy('label')->get(['id', 'label', 'path']);
    }
};
?>

<div class="space-y-5">
    <header class="flex items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('Media') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ __('Scans, receipts, photos. Click a tile to preview.') }}</p>
        </div>
        <dl class="flex gap-5 text-xs">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Total') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ $this->counts['total'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Size') }}</dt>
                <dd class="mt-0.5 tabular-nums text-neutral-200">{{ FileSize::format($this->counts['total_bytes']) }}</dd>
            </div>
            @if ($this->counts['ocr_pending'] > 0)
                <div>
                    <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('OCR pending') }}</dt>
                    <dd class="mt-0.5 tabular-nums text-amber-400">{{ $this->counts['ocr_pending'] }}</dd>
                </div>
            @endif
        </dl>
    </header>

    <form wire:submit.prevent class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-800 bg-neutral-900/40 p-4" aria-label="{{ __('Filters') }}">
        <div>
            <label for="me-q" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="me-q" type="text"
                   class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="{{ __('Filename or OCR text…') }}">
        </div>
        <div>
            <label for="me-mime" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Type') }}</label>
            <select wire:model.live="mimeFilter" id="me-mime"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('All') }}</option>
                <option value="image">{{ __('Images') }}</option>
                <option value="pdf">{{ __('PDFs') }}</option>
                <option value="other">{{ __('Other') }}</option>
            </select>
        </div>
        @if ($this->folders->isNotEmpty())
            <div>
                <label for="me-folder" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Folder') }}</label>
                <select wire:model.live="folderFilter" id="me-folder"
                        class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($this->folders as $f)
                        <option value="{{ $f->id }}">{{ $f->label ?? $f->path }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div>
            <label for="me-ocr" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('OCR') }}</label>
            <select wire:model.live="ocrFilter" id="me-ocr"
                    class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">{{ __('Any') }}</option>
                @foreach (App\Support\Enums::mediaOcrStatuses() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if ($this->media->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No media match those filters.') }}
        </div>
    @else
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
            @foreach ($this->media as $m)
                @php
                    $isImage = $m->mime && str_starts_with($m->mime, 'image/');
                    $isPdf = $m->mime === 'application/pdf';
                    $ext = strtoupper(pathinfo($m->original_name ?? $m->path, PATHINFO_EXTENSION) ?: '—');
                @endphp
                <button type="button"
                        wire:click="openPreview({{ $m->id }})"
                        wire:key="me-tile-{{ $m->id }}"
                        class="group flex flex-col overflow-hidden rounded-lg border border-neutral-800 bg-neutral-900/40 text-left transition hover:border-neutral-600 hover:bg-neutral-800/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <div class="relative aspect-square bg-neutral-900">
                        @if ($isImage)
                            <img src="{{ route('media.file', $m) }}"
                                 alt="{{ $m->original_name ?? '' }}"
                                 loading="lazy"
                                 class="h-full w-full object-cover">
                        @else
                            <div class="flex h-full w-full items-center justify-center">
                                <div class="text-center">
                                    <div class="font-mono text-2xl font-semibold tracking-wider text-neutral-500">{{ $ext }}</div>
                                    @if ($isPdf)
                                        <div class="mt-1 text-[10px] uppercase tracking-wider text-neutral-600">{{ __('PDF') }}</div>
                                    @endif
                                </div>
                            </div>
                        @endif
                        @if ($m->ocr_status === 'pending')
                            <span class="absolute right-1 top-1 rounded bg-amber-900/60 px-1.5 py-0.5 text-[9px] uppercase tracking-wider text-amber-300">OCR</span>
                        @endif
                    </div>
                    <div class="border-t border-neutral-800 px-2 py-1.5">
                        <div class="truncate text-[11px] text-neutral-200" title="{{ $m->original_name }}">
                            {{ $m->original_name ?? basename($m->path) }}
                        </div>
                        <div class="flex items-baseline justify-between gap-1 text-[10px] text-neutral-500">
                            <span class="tabular-nums">{{ FileSize::format($m->size) }}</span>
                            @if ($m->captured_at)
                                <span class="tabular-nums">{{ Formatting::date($m->captured_at) }}</span>
                            @endif
                        </div>
                    </div>
                </button>
            @endforeach
        </div>
    @endif

    @if ($this->currentMedia)
        @php
            $m = $this->currentMedia;
            $isImage = $m->mime && str_starts_with($m->mime, 'image/');
            $isPdf = $m->mime === 'application/pdf';
        @endphp
        <div x-cloak x-data
             x-on:keydown.escape.window="$wire.closePreview()"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
             role="dialog" aria-modal="true" aria-label="{{ __('Media preview') }}"
             x-on:click.self="$wire.closePreview()">
            <div class="flex max-h-[90vh] w-full max-w-5xl overflow-hidden rounded-xl border border-neutral-800 bg-neutral-950 shadow-2xl">
                <div class="flex flex-1 items-center justify-center bg-black p-4">
                    @if ($isImage)
                        <img src="{{ route('media.file', $m) }}" alt="{{ $m->original_name }}" class="max-h-[82vh] max-w-full rounded-md shadow-lg" />
                    @elseif ($isPdf)
                        <embed src="{{ route('media.file', $m) }}" type="application/pdf" class="h-[82vh] w-full rounded-md" />
                    @else
                        <div class="text-center text-neutral-400">
                            <div class="font-mono text-5xl font-semibold tracking-wider">
                                {{ strtoupper(pathinfo($m->original_name ?? $m->path, PATHINFO_EXTENSION) ?: '—') }}
                            </div>
                            <a href="{{ route('media.file', $m) }}" target="_blank" rel="noopener"
                               class="mt-3 inline-block rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-200 hover:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                {{ __('Download') }}
                            </a>
                        </div>
                    @endif
                </div>
                <aside class="flex w-80 shrink-0 flex-col border-l border-neutral-800 bg-neutral-950">
                    <header class="flex items-center justify-between gap-2 border-b border-neutral-800 px-4 py-3">
                        <h3 class="truncate text-sm font-medium text-neutral-100" title="{{ $m->original_name }}">
                            {{ $m->original_name ?: basename($m->path) }}
                        </h3>
                        <button type="button" wire:click="closePreview" aria-label="{{ __('Close') }}"
                                class="rounded-md p-1 text-neutral-500 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </header>
                    <div class="flex-1 space-y-4 overflow-y-auto px-4 py-4 text-sm">
                        <div>
                            <label for="me-name" class="mb-1 block text-xs text-neutral-400">{{ __('Filename') }}</label>
                            <input wire:model="draft_original_name" id="me-name" type="text"
                                   class="w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            @error('draft_original_name')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="me-folder-edit" class="mb-1 block text-xs text-neutral-400">{{ __('Folder') }}</label>
                            <select wire:model="draft_folder_id" id="me-folder-edit"
                                    class="w-full rounded-md border border-neutral-700 bg-neutral-900 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                <option value="">—</option>
                                @foreach ($this->folders as $f)
                                    <option value="{{ $f->id }}">{{ $f->label ?? $f->path }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="me-captured" class="mb-1 block text-xs text-neutral-400">{{ __('Captured at') }}</label>
                            <input wire:model="draft_captured_at" id="me-captured" type="datetime-local"
                                   class="w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            @error('draft_captured_at')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="me-tags" class="mb-1 block text-xs text-neutral-400">{{ __('Tags') }}</label>
                            <input wire:model="draft_tag_list" id="me-tags" type="text"
                                   placeholder="#receipts 2026-q2"
                                   class="w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 font-mono text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <p class="mt-1 text-[11px] text-neutral-500">{{ __('Space or comma separated. # optional.') }}</p>
                        </div>
                        <dl class="grid grid-cols-2 gap-2 rounded-md border border-neutral-800 bg-neutral-900/60 p-3 text-[11px] text-neutral-400">
                            <div><dt class="text-neutral-500">{{ __('Size') }}</dt><dd class="tabular-nums text-neutral-200">{{ FileSize::format($m->size) }}</dd></div>
                            <div><dt class="text-neutral-500">{{ __('Type') }}</dt><dd class="text-neutral-200">{{ $m->mime ?: '—' }}</dd></div>
                            <div>
                                <dt class="text-neutral-500">{{ __('OCR') }}</dt>
                                <dd class="flex items-center gap-2 text-neutral-200">
                                    <span>{{ $m->ocr_status ?: '—' }}</span>
                                    @if (in_array($m->ocr_status, ['pending', 'failed', 'done'], true) && str_starts_with((string) $m->mime, 'image/'))
                                        <button type="button" wire:click="retryOcr"
                                                class="rounded px-1.5 py-0.5 text-[10px] text-neutral-400 hover:bg-neutral-800 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-neutral-300">
                                            <span wire:loading.remove wire:target="retryOcr">{{ __('retry') }}</span>
                                            <span wire:loading wire:target="retryOcr">{{ __('running…') }}</span>
                                        </button>
                                    @endif
                                </dd>
                            </div>
                            <div><dt class="text-neutral-500">{{ __('Uploaded') }}</dt><dd class="tabular-nums text-neutral-200">{{ Formatting::date($m->created_at) }}</dd></div>
                        </dl>
                        @if ($m->ocr_text)
                            <details class="rounded-md border border-neutral-800 bg-neutral-900/60 text-[11px]">
                                <summary class="cursor-pointer px-3 py-2 text-neutral-400 hover:text-neutral-200">{{ __('Extracted text') }}</summary>
                                <pre class="max-h-60 overflow-auto whitespace-pre-wrap border-t border-neutral-800 px-3 py-2 font-mono text-neutral-200">{{ $m->ocr_text }}</pre>
                            </details>
                        @endif
                    </div>
                    <footer class="flex items-center justify-between gap-2 border-t border-neutral-800 bg-neutral-900/50 px-4 py-3">
                        <button type="button"
                                wire:click="deleteMedia"
                                wire:confirm="{{ __('Delete this file permanently? Attachments to other records are also removed.') }}"
                                class="rounded-md border border-rose-800/40 bg-rose-900/30 px-3 py-1.5 text-xs font-medium text-rose-100 hover:bg-rose-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Delete') }}
                        </button>
                        <div class="flex items-center gap-2">
                            <a href="{{ route('media.file', $m) }}" target="_blank" rel="noopener"
                               class="rounded-md px-3 py-1.5 text-xs text-neutral-400 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                {{ __('Download') }}
                            </a>
                            <button type="button" wire:click="saveMetadata"
                                    class="rounded-md bg-neutral-100 px-4 py-1.5 text-xs font-medium text-neutral-900 hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                <span wire:loading.remove wire:target="saveMetadata">{{ __('Save') }}</span>
                                <span wire:loading wire:target="saveMetadata">{{ __('Saving…') }}</span>
                            </button>
                        </div>
                    </footer>
                </aside>
            </div>
        </div>
    @endif
</div>

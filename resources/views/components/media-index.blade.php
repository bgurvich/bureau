<?php

use App\Jobs\GenerateMediaThumbnail;
use App\Jobs\OcrMedia;
use App\Models\Contact;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\RecurringRule;
use App\Models\Tag;
use App\Support\CurrentHousehold;
use App\Support\FileSize;
use App\Support\Formatting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new
#[Layout('components.layouts.app', ['title' => 'Media'])]
class extends Component
{
    use WithFileUploads;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    /**
     * Bulk-select state. When any entry is present, the grid flips into
     * "bulk mode": checkboxes stay visible on every tile (not just on hover)
     * and a bulk-actions bar appears above the grid.
     *
     * @var array<int, int>  list of Media ids currently selected
     */
    public array $selectedIds = [];

    /** Batch-level upload failure (capacity, client-side rejections). */
    public ?string $uploadError = null;

    /** Flash on successful batch; null when idle. */
    public ?int $uploadedCount = null;

    /** How many duplicates were skipped in the last batch (hash match). */
    public int $uploadedDuplicates = 0;

    /**
     * PHP default max_file_uploads is 20. Larger batches get silently
     * truncated at the server; cap client-side with a loud error.
     */
    private const MAX_FILES_PER_BATCH = 20;

    private const MAX_BYTES_PER_FILE = 20 * 1024 * 1024;

    /** Thumbnail grid density. sm = denser rows, md = default, lg = roomier. */
    #[Url(as: 'size')]
    public string $tileSize = 'md';

    #[Url(as: 'mime')]
    public string $mimeFilter = '';

    #[Url(as: 'folder')]
    public string $folderFilter = '';

    #[Url(as: 'ocr')]
    public string $ocrFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'source')]
    public string $sourceFilter = '';

    #[Url(as: 'entity')]
    public string $entityFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $previewId = null;

    #[Url(as: 'focus')]
    public ?int $focus = null;

    public function mount(): void
    {
        if ($this->focus) {
            $this->openPreview($this->focus);
            $this->focus = null;
        }
    }

    /**
     * Ingest a batch of dropped/clicked uploads into Media rows.
     *
     *   - File-level dedup by sha256 hash (per-household). Duplicate uploads
     *     are silently skipped; count surfaced on the tile so the user knows
     *     why the total didn't go up by N.
     *   - PDFs + images queue OCR; other types (audio/video/etc) aren't
     *     accepted — the Media surface is for scans and photos.
     *   - try/catch per file so one bad upload never aborts the rest.
     */
    public function updatedUploads(): void
    {
        $this->uploadError = null;
        $this->uploadedCount = null;
        $this->uploadedDuplicates = 0;

        $incoming = array_values(array_filter($this->uploads ?? []));
        if (count($incoming) > self::MAX_FILES_PER_BATCH) {
            $dropped = count($incoming) - self::MAX_FILES_PER_BATCH;
            $this->uploadError = __(':n extra file(s) ignored — upload at most :max at a time.', [
                'n' => $dropped, 'max' => self::MAX_FILES_PER_BATCH,
            ]);
            $incoming = array_slice($incoming, 0, self::MAX_FILES_PER_BATCH);
        }

        $household = CurrentHousehold::get();
        $created = 0;
        $dupes = 0;
        $errors = [];

        foreach ($incoming as $file) {
            $name = (string) $file->getClientOriginalName();
            try {
                $size = (int) $file->getSize();
                $mime = (string) $file->getMimeType();

                if ($size > self::MAX_BYTES_PER_FILE) {
                    $errors[] = __(':f: too large (:mb MB > :max MB)', [
                        'f' => $name,
                        'mb' => number_format($size / 1024 / 1024, 1),
                        'max' => self::MAX_BYTES_PER_FILE / 1024 / 1024,
                    ]);

                    continue;
                }

                // Accept scans + photos; reject audio/video/archives — the
                // Media surface is single-asset preview-oriented. Statement
                // bulk upload is a separate surface.
                if (! str_starts_with($mime, 'image/') && $mime !== 'application/pdf') {
                    $errors[] = __(':f: type :m not accepted (image or PDF only)', [
                        'f' => $name, 'm' => $mime,
                    ]);

                    continue;
                }

                $bytes = @file_get_contents($file->getRealPath());
                if ($bytes === false) {
                    $errors[] = __(':f: could not read file', ['f' => $name]);

                    continue;
                }
                $hash = hash('sha256', $bytes);

                $existing = Media::where('hash', $hash)
                    ->when($household, fn ($q) => $q->where('household_id', $household->id))
                    ->first();
                if ($existing) {
                    $dupes++;

                    continue;
                }

                $dir = 'uploads/'.($household?->id ?? 0).'/'.date('Y/m');
                $path = $file->store($dir, 'local');
                if (! $path) {
                    $errors[] = __(':f: storage write failed', ['f' => $name]);

                    continue;
                }

                $media = Media::create([
                    'household_id' => $household?->id,
                    'disk' => 'local',
                    'source' => 'upload',
                    'path' => $path,
                    'original_name' => $name,
                    'mime' => $mime,
                    'size' => $size,
                    'hash' => $hash,
                    'captured_at' => now(),
                    'ocr_status' => 'pending',
                ]);

                OcrMedia::dispatch($media->id);
                if ($mime === 'application/pdf') {
                    GenerateMediaThumbnail::dispatch($media->id);
                }
                $created++;
            } catch (\Throwable $e) {
                Log::warning('Media upload failed for one file', [
                    'name' => $name, 'error' => $e->getMessage(),
                ]);
                $errors[] = __(':f: :msg', ['f' => $name, 'msg' => $e->getMessage()]);
            }
        }

        $this->uploads = [];
        $this->uploadedCount = $created;
        $this->uploadedDuplicates = $dupes;

        if ($errors !== []) {
            // Prepend per-file errors to the batch-level line. Truncate so a
            // pathological batch of 20 failures doesn't blow up the UI.
            $msg = implode(' · ', array_slice($errors, 0, 5));
            if (count($errors) > 5) {
                $msg .= ' · '.__('(:n more)', ['n' => count($errors) - 5]);
            }
            $this->uploadError = trim(($this->uploadError ? $this->uploadError.' · ' : '').$msg);
        }

        unset($this->media, $this->counts);
    }

    public string $draft_original_name = '';

    public ?int $draft_folder_id = null;

    public string $draft_captured_at = '';

    public string $draft_tag_list = '';

    public function setTileSize(string $size): void
    {
        if (in_array($size, ['sm', 'md', 'lg'], true)) {
            $this->tileSize = $size;
        }
    }

    /** Toggle a single media row in/out of the selection set. */
    public function toggleSelect(int $id): void
    {
        if (in_array($id, $this->selectedIds, true)) {
            $this->selectedIds = array_values(array_diff($this->selectedIds, [$id]));
        } else {
            $this->selectedIds[] = $id;
        }
    }

    /** Extend the selection to every media row currently in the filtered grid. */
    public function selectAllVisible(): void
    {
        $this->selectedIds = $this->media->pluck('id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    public function clearSelection(): void
    {
        $this->selectedIds = [];
    }

    /**
     * Delete every selected media row. File bytes go with them (including
     * thumb_path), pivots cascade via the mediables FK. One call to the
     * shared deleteMediaById keeps per-row cleanup consistent with the
     * single-tile hover-delete.
     */
    public function bulkDelete(): void
    {
        foreach ($this->selectedIds as $id) {
            $this->deleteMediaById((int) $id);
        }
        $this->selectedIds = [];
    }

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

    /**
     * Re-queue PDF thumbnail generation for the currently-previewed media.
     * Clears the existing thumb_path so the job regenerates from scratch —
     * useful when pdftoppm failed previously or the source bytes changed.
     */
    public function retryThumbnail(): void
    {
        $m = Media::find($this->previewId);
        if (! $m || $m->mime !== 'application/pdf') {
            return;
        }
        $m->forceFill(['thumb_path' => null])->save();
        GenerateMediaThumbnail::dispatch($m->id);
        unset($this->media);
    }

    public function dismissProcessing(): void
    {
        $m = Media::find($this->previewId);
        if (! $m) {
            return;
        }
        $m->forceFill(['processed_at' => now()])->save();
        \App\Models\MailMessage::cascadeProcessedFromMedia($m->id);
        unset($this->media, $this->counts);
    }

    public function reopenProcessing(): void
    {
        $m = Media::find($this->previewId);
        if (! $m) {
            return;
        }
        $m->forceFill(['processed_at' => null])->save();
        unset($this->media, $this->counts);
    }

    public function deleteMedia(): void
    {
        $this->deleteMediaById($this->previewId);
        $this->closePreview();
    }

    /**
     * Delete a Media row by id — used by the hover-delete button on tiles in
     * the grid, where there's no preview open. Same cleanup shape as the
     * modal-driven deleteMedia(): remove disk bytes, remove row (pivots
     * cascade), bust computed caches so the grid refreshes.
     */
    public function deleteMediaById(?int $id): void
    {
        if ($id === null) {
            return;
        }
        $m = Media::find($id);
        if (! $m) {
            return;
        }

        try {
            Storage::disk($m->disk ?: 'local')->delete($m->path);
            if (! empty($m->thumb_path)) {
                Storage::disk($m->disk ?: 'local')->delete($m->thumb_path);
            }
        } catch (\Throwable) {
            // File already gone or disk misconfigured — still remove the row.
        }

        $m->delete();

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

    /**
     * Find an existing RecurringRule that looks like it already tracks this
     * bill, so the UI can nudge the user toward "record payment against the
     * existing rule" instead of creating a duplicate. Matches the extracted
     * vendor against rule.title (case-insensitive exact) or against the
     * counterparty contact's display_name / organization. BelongsToHousehold
     * already scopes to the current household.
     */
    #[Computed]
    public function duplicateRule(): ?RecurringRule
    {
        $m = $this->currentMedia;
        if (! $m) {
            return null;
        }
        $extracted = $m->ocr_extracted;
        if (! is_array($extracted)) {
            return null;
        }
        $vendor = is_string($extracted['vendor'] ?? null) ? trim((string) $extracted['vendor']) : '';
        if ($vendor === '') {
            return null;
        }
        $needle = mb_strtolower($vendor);

        $contactIds = Contact::query()
            ->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(display_name) = ?', [$needle])
                    ->orWhereRaw('LOWER(organization) = ?', [$needle]);
            })
            ->pluck('id');

        return RecurringRule::query()
            ->where('kind', 'bill')
            ->where('active', true)
            ->where(function ($q) use ($needle, $contactIds) {
                $q->whereRaw('LOWER(title) = ?', [$needle]);
                if ($contactIds->isNotEmpty()) {
                    $q->orWhereIn('counterparty_contact_id', $contactIds);
                }
            })
            ->with('counterparty:id,display_name')
            ->orderByDesc('id')
            ->first();
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
            ->when($this->statusFilter === 'unprocessed', fn ($q) => $q
                ->whereNull('processed_at')
                ->where('ocr_status', 'done')
                ->whereNotNull('ocr_extracted')
            )
            ->when($this->statusFilter === 'processed', fn ($q) => $q->whereNotNull('processed_at'))
            ->when($this->sourceFilter !== '', fn ($q) => $q->where('source', $this->sourceFilter))
            ->when($this->entityFilter === 'unattached', fn ($q) => $q
                ->whereNotExists(fn ($sub) => $sub
                    ->selectRaw('1')->from('mediables')
                    ->whereColumn('mediables.media_id', 'media.id')
                )
            )
            ->when($this->entityFilter !== '' && $this->entityFilter !== 'unattached', fn ($q) => $q
                ->whereExists(fn ($sub) => $sub
                    ->selectRaw('1')->from('mediables')
                    ->whereColumn('mediables.media_id', 'media.id')
                    ->where('mediables.mediable_type', $this->entityTypeMap()[$this->entityFilter] ?? '')
                )
            )
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
            'unprocessed' => Media::whereNull('processed_at')
                ->where('ocr_status', 'done')
                ->whereNotNull('ocr_extracted')
                ->count(),
        ];
    }

    /**
     * Map friendly filter values to Eloquent-morph class names recorded in
     * the mediables pivot. Kept in one place so the view and the query stay
     * in sync as new HasMedia consumers are added.
     *
     * @return array<string, class-string>
     */
    public function entityTypeMap(): array
    {
        return [
            'bill' => \App\Models\RecurringRule::class,
            'transaction' => \App\Models\Transaction::class,
            'transfer' => \App\Models\Transfer::class,
            'document' => \App\Models\Document::class,
            'inventory' => \App\Models\InventoryItem::class,
            'property' => \App\Models\Property::class,
            'vehicle' => \App\Models\Vehicle::class,
            'contract' => \App\Models\Contract::class,
            'contact' => \App\Models\Contact::class,
            'account' => \App\Models\Account::class,
            'note' => \App\Models\Note::class,
            'task' => \App\Models\Task::class,
            'appointment' => \App\Models\Appointment::class,
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
            <p class="mt-1 text-xs text-neutral-500">{{ __('Scans, receipts, photos. Drop here to upload. Click a tile to preview.') }}</p>
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

    {{-- Drop zone: drag images / PDFs in, or click to pick. Same guard shape
         as statements-import (20 files, 20 MB each, try/catch per file, upload-
         error surface, full-cover spinner during stream, Livewire-level
         error bridge for post_max_size / nginx 413 / network drops). --}}
    <div x-data="{ over: false, uploadError: '' }"
         x-on:dragover.prevent="over = true"
         x-on:dragleave.prevent="over = false"
         x-on:drop.prevent="
            over = false;
            if (!$event.dataTransfer?.files?.length) return;
            const dt = new DataTransfer();
            for (const f of $event.dataTransfer.files) dt.items.add(f);
            $refs.mediaUploads.files = dt.files;
            $refs.mediaUploads.dispatchEvent(new Event('change', { bubbles: true }));
         "
         x-on:livewire-upload-error.window="
            uploadError = ($event.detail?.message
                ?? @js(__('Upload failed. A file may be too large, blocked by your server config, or your connection dropped.')));
         "
         x-on:livewire-upload-finish.window="uploadError = ''"
         :class="over
            ? 'border-emerald-500 bg-emerald-950/20'
            : 'border-neutral-700 bg-neutral-900/40'"
         class="relative rounded-xl border-2 border-dashed p-5 transition-colors">
        <label class="flex cursor-pointer flex-col items-center gap-2 text-sm text-neutral-300">
            <input x-ref="mediaUploads" type="file" wire:model="uploads" multiple
                   accept="image/*,application/pdf"
                   class="sr-only" aria-label="{{ __('Upload media') }}">
            <svg class="h-8 w-8 text-neutral-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 4v12m-4-4 4 4 4-4"/><path d="M4 20h16"/>
            </svg>
            <span x-text="over ? @js(__('Drop to upload')) : @js(__('Click to choose files, or drop them here'))">{{ __('Click to choose files, or drop them here') }}</span>
            <span class="text-[11px] text-neutral-500">{{ __('Images and PDFs accepted · up to :n files, :m MB each', ['n' => 20, 'm' => 20]) }}</span>
        </label>

        <div wire:loading.flex wire:target="uploads"
             role="status" aria-live="polite"
             class="absolute inset-0 flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-emerald-500 bg-neutral-950/85 text-sm font-medium text-emerald-200">
            <svg class="h-6 w-6 animate-spin text-emerald-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            </svg>
            <span>{{ __('Uploading…') }}</span>
        </div>

        <div x-show="uploadError" x-cloak x-transition.opacity
             role="alert"
             class="mt-3 flex items-start gap-2 rounded-md border border-rose-800/50 bg-rose-950/30 px-3 py-2 text-xs text-rose-200">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-rose-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/>
            </svg>
            <span x-text="uploadError" class="flex-1"></span>
            <button type="button" x-on:click="uploadError = ''"
                    class="shrink-0 rounded px-1 text-rose-300 hover:text-rose-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                    aria-label="{{ __('Dismiss') }}">×</button>
        </div>
    </div>

    @if ($uploadError)
        <div role="alert" class="rounded-md border border-rose-800/50 bg-rose-950/30 px-4 py-2 text-xs text-rose-200">
            {{ $uploadError }}
        </div>
    @endif

    @if ($uploadedCount !== null && $uploadedCount > 0)
        <div role="status" class="rounded-md border border-emerald-800/50 bg-emerald-950/30 px-4 py-2 text-xs text-emerald-200">
            {{ __(':n uploaded; queued for OCR.', ['n' => $uploadedCount]) }}
            @if ($uploadedDuplicates > 0)
                {{ __(':d duplicate(s) skipped.', ['d' => $uploadedDuplicates]) }}
            @endif
        </div>
    @elseif ($uploadedCount === 0 && $uploadedDuplicates > 0)
        <div role="status" class="rounded-md border border-amber-800/50 bg-amber-950/30 px-4 py-2 text-xs text-amber-200">
            {{ __('Nothing new — :d duplicate(s) skipped.', ['d' => $uploadedDuplicates]) }}
        </div>
    @endif

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

        {{-- Tile-size toggle lives on the filter row, right-aligned via ml-auto
             so it's visually adjacent to the filters it adjusts. `items-end` on
             the parent <form> aligns it with the bottom of the labelled filter
             selects rather than the top. --}}
        <div class="ml-auto" role="group" aria-label="{{ __('Thumbnail size') }}">
            <div class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Size') }}</div>
            <div class="mt-1 inline-flex rounded-md border border-neutral-700 bg-neutral-900 p-0.5">
                @foreach (['sm' => __('Small'), 'md' => __('Medium'), 'lg' => __('Large')] as $size => $label)
                    <button type="button"
                            wire:click="setTileSize('{{ $size }}')"
                            aria-pressed="{{ $this->tileSize === $size ? 'true' : 'false' }}"
                            class="rounded px-2 py-1 text-xs transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $this->tileSize === $size ? 'bg-neutral-700 text-neutral-100' : 'text-neutral-400 hover:text-neutral-200' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    </form>

    @if (! empty($selectedIds))
        {{-- Bulk-mode bar: appears the instant the first tile is selected and
             sticks to the top of the grid so actions stay reachable while the
             user keeps picking. --}}
        <div role="region" aria-label="{{ __('Bulk actions') }}"
             class="sticky top-0 z-20 flex flex-wrap items-center gap-3 rounded-xl border border-emerald-800/50 bg-emerald-950/40 px-4 py-2 text-sm text-emerald-100 shadow-lg backdrop-blur">
            <span class="font-semibold tabular-nums">{{ trans_choice(':n selected|:n selected', count($selectedIds), ['n' => count($selectedIds)]) }}</span>
            <div class="ml-auto flex items-center gap-2">
                <button type="button" wire:click="selectAllVisible"
                        class="rounded-md border border-emerald-800/60 bg-emerald-900/30 px-3 py-1 text-xs text-emerald-100 hover:bg-emerald-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Select all visible') }}
                </button>
                <button type="button" wire:click="bulkDelete"
                        wire:confirm="{{ __('Delete :n selected file(s)? This cannot be undone.', ['n' => count($selectedIds)]) }}"
                        class="rounded-md border border-rose-800/50 bg-rose-900/30 px-3 py-1 text-xs text-rose-200 hover:bg-rose-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Delete selected') }}
                </button>
                <button type="button" wire:click="clearSelection"
                        class="rounded-md border border-neutral-700 bg-neutral-900/50 px-3 py-1 text-xs text-neutral-200 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Clear selection') }}
                </button>
            </div>
        </div>
    @endif

    @if ($this->media->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No media match those filters.') }}
        </div>
    @else
        @php
            $gridClass = match ($this->tileSize) {
                'sm' => 'grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 xl:grid-cols-10',
                'lg' => 'grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4',
                default => 'grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6',
            };
        @endphp
        <div class="{{ $gridClass }}">
            @foreach ($this->media as $m)
                @php
                    $isImage = $m->mime && str_starts_with($m->mime, 'image/');
                    $isPdf = $m->mime === 'application/pdf';
                    $ext = strtoupper(pathinfo($m->original_name ?? $m->path, PATHINFO_EXTENSION) ?: '—');
                    // Moved out of the @php(...) shorthand: the shorthand's regex
                    // doesn't balance nested parens, so `in_array(..., true)`
                    // truncated the expression and broke Blade compilation.
                    $isSelected = in_array($m->id, $selectedIds, true);
                @endphp
                {{-- Wrapper is a div (not a button) so the delete button can
                     nest inside — nested buttons are invalid HTML. The outer
                     acts as the preview-open trigger via its own click handler;
                     the delete button sits on top with stopPropagation so its
                     clicks don't bubble into "open preview". `group` + `relative`
                     enable the hover-reveal + absolute positioning of the
                     delete button. --}}
                <div wire:key="me-tile-{{ $m->id }}"
                     class="group relative {{ $isSelected ? 'ring-2 ring-emerald-500/70 ring-offset-2 ring-offset-neutral-950 rounded-lg' : '' }}">
                    <button type="button"
                            wire:click="openPreview({{ $m->id }})"
                            class="flex w-full flex-col overflow-hidden rounded-lg border border-neutral-800 bg-neutral-900/40 text-left transition hover:border-neutral-600 hover:bg-neutral-800/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <div class="relative aspect-square bg-neutral-900">
                            @if ($isImage)
                                <img src="{{ route('media.file', $m) }}"
                                     alt="{{ $m->original_name ?? '' }}"
                                     loading="lazy"
                                     class="h-full w-full object-cover">
                            @elseif ($isPdf && ! empty($m->thumb_path))
                                {{-- PDF thumbnail generated by GenerateMediaThumbnail job.
                                     Tiny "PDF" chip in corner so file type stays legible
                                     at a glance — the thumbnail itself can be ambiguous
                                     for statement-style pages that look like text blocks. --}}
                                <img src="{{ route('media.thumb', $m) }}"
                                     alt="{{ $m->original_name ?? __('PDF preview') }}"
                                     loading="lazy"
                                     class="h-full w-full object-cover">
                                <span class="pointer-events-none absolute left-1 bottom-1 rounded bg-neutral-900/80 px-1 text-[10px] font-semibold uppercase tracking-wider text-neutral-300">PDF</span>
                            @else
                                <div class="flex h-full w-full items-center justify-center">
                                    <div class="text-center">
                                        <div class="font-mono text-2xl font-semibold tracking-wider text-neutral-500">{{ $ext }}</div>
                                        @if ($isPdf)
                                            <div class="mt-1 text-[10px] uppercase tracking-wider {{ $m->thumb_status === 'failed' ? 'text-rose-400' : 'text-neutral-600' }}">
                                                {{ $m->thumb_status === 'failed' ? __('PDF · thumb failed') : __('PDF · rendering…') }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                            @if ($m->ocr_status === 'pending')
                                <span class="absolute right-1 top-1 rounded bg-amber-900/60 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-amber-300">OCR</span>
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

                    {{-- Hover-reveal select checkbox (top-left). Always visible
                         once the user has selected at least one tile (bulk mode)
                         so the rest of the grid surfaces its affordance. Clicks
                         stopPropagation so toggling doesn't also openPreview. --}}
                    <button type="button"
                            wire:click.stop="toggleSelect({{ $m->id }})"
                            x-on:click.stop
                            aria-label="{{ $isSelected ? __('Unselect media') : __('Select media') }}"
                            aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                            class="absolute -left-2 -top-2 flex h-6 w-6 items-center justify-center rounded-full border shadow transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $isSelected ? 'border-emerald-500 bg-emerald-600 text-white' : 'border-neutral-700 bg-neutral-900 text-neutral-400 hover:border-emerald-500 hover:text-emerald-300' }} {{ $isSelected || ! empty($selectedIds) ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 focus-visible:opacity-100' }}">
                        <svg class="h-3.5 w-3.5 {{ $isSelected ? '' : 'hidden' }}" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                            <path d="M3 8.5 6.5 12 13 5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <svg class="h-3 w-3 {{ $isSelected ? 'hidden' : '' }}" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                            <circle cx="8" cy="8" r="5.5" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                    </button>

                    {{-- Hover-delete: appears on tile hover or keyboard focus
                         so pointer users get a quick path and keyboard users
                         can still reach it via Tab. stopPropagation on the
                         Livewire click handler prevents the click from also
                         triggering openPreview on the outer surface. --}}
                    <button type="button"
                            wire:click.stop="deleteMediaById({{ $m->id }})"
                            wire:confirm="{{ __('Delete this media file?') }}"
                            x-on:click.stop
                            aria-label="{{ __('Delete media') }}"
                            class="absolute -right-2 -top-2 flex h-6 w-6 items-center justify-center rounded-full border border-neutral-700 bg-neutral-900 text-neutral-300 opacity-0 shadow transition hover:border-rose-500 hover:text-rose-400 group-hover:opacity-100 focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <svg class="h-3 w-3" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                            <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
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
                                    @if (in_array($m->ocr_status, ['pending', 'failed', 'done'], true) && (str_starts_with((string) $m->mime, 'image/') || $m->mime === 'application/pdf'))
                                        <button type="button" wire:click="retryOcr"
                                                class="rounded px-1.5 py-0.5 text-[10px] text-neutral-400 hover:bg-neutral-800 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-neutral-300">
                                            <span wire:loading.remove wire:target="retryOcr">{{ __('retry') }}</span>
                                            <span wire:loading wire:target="retryOcr">{{ __('running…') }}</span>
                                        </button>
                                    @endif
                                </dd>
                            </div>
                            <div><dt class="text-neutral-500">{{ __('Uploaded') }}</dt><dd class="tabular-nums text-neutral-200">{{ Formatting::date($m->created_at) }}</dd></div>
                            @if ($m->mime === 'application/pdf')
                                <div>
                                    <dt class="text-neutral-500">{{ __('Thumbnail') }}</dt>
                                    <dd class="flex items-center gap-2">
                                        <span class="{{ $m->thumb_status === 'failed' ? 'text-rose-300' : 'text-neutral-200' }}">
                                            {{ match ($m->thumb_status ?? 'pending') {
                                                'done' => __('generated'),
                                                'failed' => __('failed'),
                                                'skip' => __('n/a'),
                                                default => __('pending'),
                                            } }}
                                        </span>
                                        <button type="button" wire:click="retryThumbnail"
                                                class="rounded px-1.5 py-0.5 text-[10px] text-neutral-400 hover:bg-neutral-800 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-neutral-300">
                                            <span wire:loading.remove wire:target="retryThumbnail">{{ __('regenerate') }}</span>
                                            <span wire:loading wire:target="retryThumbnail">{{ __('running…') }}</span>
                                        </button>
                                    </dd>
                                </div>
                            @endif
                        </dl>
                        @if ($m->ocr_text)
                            <details class="rounded-md border border-neutral-800 bg-neutral-900/60 text-[11px]">
                                <summary class="cursor-pointer px-3 py-2 text-neutral-400 hover:text-neutral-200">{{ __('Extracted text') }}</summary>
                                <pre class="max-h-60 overflow-auto whitespace-pre-wrap border-t border-neutral-800 px-3 py-2 font-mono text-neutral-200">{{ $m->ocr_text }}</pre>
                            </details>
                        @endif
                        @if (is_array($m->ocr_extracted) && $m->ocr_extracted !== [])
                            @php($e = $m->ocr_extracted)
                            <div class="rounded-md border border-emerald-900/40 bg-emerald-950/20 px-3 py-3 text-[11px] text-neutral-200"
                                 data-testid="ocr-extracted-summary">
                                <div class="mb-2 flex items-center justify-between">
                                    <span class="text-[10px] font-medium uppercase tracking-wider text-emerald-300">{{ __('Parsed by AI') }}</span>
                                    @if (isset($e['confidence']) && is_numeric($e['confidence']))
                                        <span class="font-mono text-[10px] text-neutral-500">{{ __('conf :n', ['n' => number_format((float) $e['confidence'], 2)]) }}</span>
                                    @endif
                                </div>
                                <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-[11px]">
                                    @if (! empty($e['vendor']))
                                        <div><dt class="text-neutral-500">{{ __('Vendor') }}</dt><dd class="text-neutral-100">{{ $e['vendor'] }}</dd></div>
                                    @endif
                                    @if (isset($e['amount']) && is_numeric($e['amount']))
                                        <div><dt class="text-neutral-500">{{ __('Amount') }}</dt><dd class="tabular-nums text-neutral-100">{{ Formatting::money((float) $e['amount'], $e['currency'] ?? (\App\Support\CurrentHousehold::get()?->default_currency ?? 'USD')) }}</dd></div>
                                    @endif
                                    @if (! empty($e['issued_on']))
                                        <div><dt class="text-neutral-500">{{ __('Issued') }}</dt><dd class="tabular-nums text-neutral-100">{{ $e['issued_on'] }}</dd></div>
                                    @endif
                                    @if (! empty($e['due_on']))
                                        <div><dt class="text-neutral-500">{{ __('Due') }}</dt><dd class="tabular-nums text-neutral-100">{{ $e['due_on'] }}</dd></div>
                                    @endif
                                    @if (! empty($e['category_suggestion']))
                                        <div><dt class="text-neutral-500">{{ __('Category') }}</dt><dd class="text-neutral-100">{{ $e['category_suggestion'] }}</dd></div>
                                    @endif
                                </dl>
                                @php($dup = $this->duplicateRule)
                                @if ($dup)
                                    <div class="mt-3 rounded-md border border-amber-800/50 bg-amber-950/30 px-3 py-2 text-[11px] text-amber-100"
                                         data-testid="ocr-duplicate-warning">
                                        {{ __('Looks like your existing bill ":t" — record a payment against it instead of making a duplicate rule.', ['t' => $dup->title]) }}
                                    </div>
                                @endif
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @if ($dup)
                                        <button type="button"
                                                wire:click="$dispatch('inspector-open', { type: 'transaction', mediaId: {{ $m->id }} })"
                                                class="rounded-md bg-emerald-600 px-2.5 py-1 text-[11px] font-medium text-white hover:bg-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                            {{ __('Record payment') }}
                                        </button>
                                        <button type="button"
                                                wire:click="$dispatch('inspector-open', { type: 'bill', mediaId: {{ $m->id }} })"
                                                class="rounded-md border border-neutral-700 bg-neutral-900 px-2.5 py-1 text-[11px] text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                            {{ __('Create new bill anyway') }}
                                        </button>
                                    @else
                                        <button type="button"
                                                wire:click="$dispatch('inspector-open', { type: 'bill', mediaId: {{ $m->id }} })"
                                                class="rounded-md border border-emerald-800/60 bg-emerald-900/40 px-2.5 py-1 text-[11px] font-medium text-emerald-100 hover:bg-emerald-900/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                            {{ __('Create bill') }}
                                        </button>
                                        <button type="button"
                                                wire:click="$dispatch('inspector-open', { type: 'transaction', mediaId: {{ $m->id }} })"
                                                class="rounded-md border border-emerald-800/60 bg-emerald-900/40 px-2.5 py-1 text-[11px] font-medium text-emerald-100 hover:bg-emerald-900/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                            {{ __('Create transaction') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @elseif ($m->extraction_status === 'pending')
                            <div class="rounded-md border border-neutral-800 bg-neutral-900/40 px-3 py-2 text-[11px] text-neutral-500">
                                {{ __('AI extraction pending…') }}
                            </div>
                        @elseif ($m->extraction_status === 'failed')
                            <div class="rounded-md border border-amber-900/40 bg-amber-950/20 px-3 py-2 text-[11px] text-amber-200">
                                {{ __('AI extraction failed — local LLM unreachable or returned unusable output.') }}
                            </div>
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

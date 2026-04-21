<?php

use App\Jobs\OcrMedia;
use App\Models\Media;
use App\Models\PhysicalMail;
use App\Support\MediaFolders;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Component;
use Livewire\WithFileUploads;

new
#[Layout('components.layouts.mobile', ['title' => 'Capture'])]
class extends Component
{
    use WithFileUploads;

    public $photo = null;

    public int $savedCount = 0;

    /**
     * Sticky kind across the session — a batch of receipts or bills
     * in one sitting shares the same kind, so don't re-pick per shot.
     * Valid values map 1:1 to MediaFolders constants.
     */
    #[Session(key: 'm-cap-kind')]
    public string $kind = 'receipt';

    private const KINDS = ['receipt', 'bill', 'document', 'post'];

    public function mount(?string $kind = null): void
    {
        // Accept a ?kind= query param so existing /m/capture/post
        // deep links (and the removed mobile tile shortcut) land on
        // the merged surface with the right kind preselected.
        if ($kind !== null && in_array($kind, self::KINDS, true)) {
            $this->kind = $kind;
        }
    }

    public function setKind(string $kind): void
    {
        if (in_array($kind, self::KINDS, true)) {
            $this->kind = $kind;
        }
    }

    public function save(bool $andContinue = true): void
    {
        $this->validate([
            'photo' => 'required|image|max:20480',
        ]);

        $originalName = $this->photo->getClientOriginalName();
        $mime = $this->photo->getMimeType();
        $size = $this->photo->getSize();
        $path = $this->photo->store('photos', 'local');

        $folderSlug = match ($this->kind) {
            'bill' => MediaFolders::BILLS,
            'document' => MediaFolders::DOCUMENTS,
            'post' => MediaFolders::POST,
            default => MediaFolders::RECEIPTS,
        };

        $media = Media::create([
            'disk' => 'local',
            'source' => 'mobile',
            'path' => $path,
            'original_name' => $originalName,
            'mime' => $mime,
            'size' => $size,
            'captured_at' => now(),
            // All four kinds are textual — queue Tesseract so the
            // text is searchable and the Inbox classifier (Tier 2)
            // can re-route later.
            'ocr_status' => 'pending',
            'folder_id' => MediaFolders::idFor($folderSlug),
        ]);

        OcrMedia::dispatch($media->id);

        // Post gets a stub physical_mail row filed under today's
        // date so it appears on /records?tab=post for follow-up
        // from desktop. The photo attaches as its cover.
        if ($this->kind === 'post') {
            $mail = PhysicalMail::create([
                'received_on' => now()->toDateString(),
                'kind' => 'other',
                'action_required' => false,
            ]);
            $mail->media()->attach($media->id, ['role' => 'photo']);
        }

        $this->savedCount++;
        $this->reset('photo');

        if (! $andContinue) {
            $this->redirectRoute('mobile.capture', navigate: false);
        }
    }
};
?>

<div class="space-y-5" x-data="{ reshoot() { $refs.photoInput.value = ''; $refs.photoInput.click(); } }">
    <header class="pt-2">
        <h1 class="text-lg font-semibold text-neutral-100">{{ __('Capture') }}</h1>
        <p class="mt-1 text-xs text-neutral-500">
            {{ __('Snap and file. OCR runs automatically; review from desktop.') }}
        </p>
    </header>

    @php
        $kinds = [
            'receipt' => __('Receipt'),
            'bill' => __('Bill'),
            'document' => __('Document'),
            'post' => __('Post'),
        ];
    @endphp

    <div role="group" aria-label="{{ __('What are you capturing?') }}"
         class="grid grid-cols-4 gap-1 rounded-full border border-neutral-800 bg-neutral-900/60 p-1 text-xs">
        @foreach ($kinds as $k => $label)
            @php($active = $kind === $k)
            <button type="button"
                    wire:click="setKind('{{ $k }}')"
                    aria-pressed="{{ $active ? 'true' : 'false' }}"
                    class="rounded-full px-2 py-1.5 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 {{ $active ? 'bg-neutral-700 text-neutral-100' : 'text-neutral-400 active:bg-neutral-800/60' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($savedCount > 0)
        <div class="rounded-lg border border-emerald-900/60 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-300" role="status" aria-live="polite">
            {{ trans_choice('{1} :count saved|[2,*] :count saved', $savedCount, ['count' => $savedCount]) }}
        </div>
    @endif

    <div class="space-y-3">
        @if ($photo && method_exists($photo, 'isPreviewable') && $photo->isPreviewable())
            <figure class="overflow-hidden rounded-2xl border border-neutral-800 bg-neutral-900">
                <img src="{{ $photo->temporaryUrl() }}" alt="{{ __('Preview') }}" class="block h-auto w-full object-cover" />
            </figure>
        @elseif ($photo)
            <div class="rounded-2xl border border-rose-900/60 bg-rose-900/20 px-3 py-6 text-center text-sm text-rose-300" role="alert">
                {{ __('That file is not an image.') }}
            </div>
        @else
            <label for="photo-input"
                   class="flex aspect-[4/5] w-full cursor-pointer flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-neutral-700 bg-neutral-900/40 text-neutral-400 active:bg-neutral-900/70">
                <svg class="h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="4" y="4" width="16" height="16" rx="2"/>
                    <circle cx="9" cy="10" r="2"/>
                    <path d="m4 18 6-6 4 4 3-3 3 3"/>
                </svg>
                <span class="text-sm">{{ __('Tap to capture a :kind', ['kind' => mb_strtolower($kinds[$kind] ?? __('photo'))]) }}</span>
            </label>
        @endif

        <input type="file"
               id="photo-input"
               x-ref="photoInput"
               wire:model="photo"
               accept="image/*"
               capture="environment"
               class="sr-only"
               aria-label="{{ __('Photo') }}">

        @error('photo')
            <div role="alert" class="text-xs text-rose-400">{{ $message }}</div>
        @enderror

        <div wire:loading wire:target="photo" class="text-xs text-neutral-500">{{ __('Uploading…') }}</div>
    </div>

    @if ($photo)
        <div class="grid grid-cols-3 gap-2">
            <button type="button" x-on:click="reshoot()"
                    class="rounded-xl border border-neutral-700 bg-neutral-900 px-3 py-3 text-sm text-neutral-200 active:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ __('Retake') }}
            </button>
            <button type="button" wire:click="save(true)" wire:loading.attr="disabled" wire:target="save"
                    class="col-span-2 rounded-xl bg-emerald-600 px-3 py-3 text-sm font-medium text-white active:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:opacity-60">
                {{ __('Save & next') }}
            </button>
        </div>
        <button type="button" wire:click="save(false)" wire:loading.attr="disabled" wire:target="save"
                class="w-full rounded-xl border border-neutral-800 bg-neutral-900 px-3 py-3 text-sm text-neutral-300 active:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 disabled:opacity-60">
            {{ __('Save & done') }}
        </button>
    @endif

    <p class="pt-2 text-center text-[11px] text-neutral-600">
        {{ __('Files land in the matching Media folder. Review and tag on desktop.') }}
    </p>
</div>

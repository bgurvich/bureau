<?php

use App\Jobs\OcrMedia;
use App\Models\Media;
use App\Models\PhysicalMail;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new
#[Layout('components.layouts.mobile', ['title' => 'Post'])]
class extends Component
{
    use WithFileUploads;

    public $photo = null;

    public int $savedCount = 0;

    /**
     * Snap a photo, create the Media row (queued for OCR so the text
     * is searchable), and create a stub PhysicalMail row with today's
     * date and action_required=false. The desktop Inspector on /records
     * (Post tab) is where the user fills in sender / subject / summary
     * later. Photo is attached via the existing `mediables` pivot so
     * it shows up on the row's card.
     */
    public function save(bool $andContinue = true): void
    {
        $this->validate([
            'photo' => 'required|image|max:20480',
        ]);

        $originalName = $this->photo->getClientOriginalName();
        $mime = $this->photo->getMimeType();
        $size = $this->photo->getSize();
        $path = $this->photo->store('photos', 'local');

        $media = Media::create([
            'disk' => 'local',
            'source' => 'mobile',
            'path' => $path,
            'original_name' => $originalName,
            'mime' => $mime,
            'size' => $size,
            'captured_at' => now(),
            'ocr_status' => 'pending',
        ]);

        OcrMedia::dispatch($media->id);

        $mail = PhysicalMail::create([
            'received_on' => now()->toDateString(),
            'kind' => 'other',
            'action_required' => false,
        ]);
        $mail->media()->attach($media->id, ['role' => 'photo']);

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
        <h1 class="text-lg font-semibold text-neutral-100">{{ __('Post') }}</h1>
        <p class="mt-1 text-xs text-neutral-500">
            {{ __('Snap the envelope or the letter. A stub is filed under Records → Post with today\'s date; fill in the details from desktop later.') }}
        </p>
    </header>

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
            <label for="post-input"
                   class="flex aspect-[4/5] w-full cursor-pointer flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-neutral-700 bg-neutral-900/40 text-neutral-400 active:bg-neutral-900/70">
                <svg class="h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 7l9 6 9-6"/>
                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                </svg>
                <span class="text-sm">{{ __('Tap to capture') }}</span>
            </label>
        @endif

        <input type="file"
               id="post-input"
               x-ref="photoInput"
               wire:model="photo"
               accept="image/*"
               capture="environment"
               class="sr-only"
               aria-label="{{ __('Post photo') }}">

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
</div>

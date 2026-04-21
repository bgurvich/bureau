<?php

use App\Models\InventoryItem;
use App\Models\Media;
use App\Models\Property;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Component;
use Livewire\WithFileUploads;

new
#[Layout('components.layouts.mobile', ['title' => 'Photo inventory'])]
class extends Component
{
    use WithFileUploads;

    public $photo = null;

    public int $savedCount = 0;

    /** Sticky location fields — persist across photos in the same session so
     *  walking a closet/garage doesn't require re-picking per shot. */
    #[Session(key: 'm-inv-property')]
    public ?int $stickyProperty = null;

    #[Session(key: 'm-inv-room')]
    public string $stickyRoom = '';

    #[Session(key: 'm-inv-container')]
    public string $stickyContainer = '';

    public bool $editLocation = false;

    public function toggleLocation(): void
    {
        $this->editLocation = ! $this->editLocation;
    }

    public function clearLocation(): void
    {
        $this->stickyProperty = null;
        $this->stickyRoom = '';
        $this->stickyContainer = '';
    }

    public function save(bool $andContinue = true): void
    {
        $this->validate([
            'photo' => 'required|image|max:20480',
            'stickyProperty' => 'nullable|integer|exists:properties,id',
            'stickyRoom' => 'nullable|string|max:120',
            'stickyContainer' => 'nullable|string|max:120',
        ]);

        $originalName = $this->photo->getClientOriginalName();
        $mime = $this->photo->getMimeType();
        $size = $this->photo->getSize();

        $path = $this->photo->store('inventory-captures', 'local');

        $media = Media::create([
            'disk' => 'local',
            'source' => 'mobile',
            'path' => $path,
            'original_name' => $originalName,
            'mime' => $mime,
            'size' => $size,
            'captured_at' => now(),
            'ocr_status' => 'skip',
        ]);

        $item = InventoryItem::create([
            'name' => __('Captured :when', ['when' => now()->format('M j, H:i')]),
            'location_property_id' => $this->stickyProperty,
            'room' => trim($this->stickyRoom) ?: null,
            'container' => trim($this->stickyContainer) ?: null,
        ]);

        $item->media()->attach($media->id, ['role' => 'photo', 'position' => 0]);

        $this->savedCount++;
        $this->reset('photo');
        $this->dispatch('photo-saved');

        if (! $andContinue) {
            $this->redirectRoute('mobile.capture', navigate: false);
        }
    }

    /**
     * @return Collection<int, Property>
     */
    #[Computed]
    public function properties(): Collection
    {
        return Property::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function locationSummary(): string
    {
        $parts = [];
        if ($this->stickyProperty) {
            $label = $this->properties->firstWhere('id', $this->stickyProperty)?->name;
            if ($label) {
                $parts[] = $label;
            }
        }
        if (trim($this->stickyRoom) !== '') {
            $parts[] = trim($this->stickyRoom);
        }
        if (trim($this->stickyContainer) !== '') {
            $parts[] = trim($this->stickyContainer);
        }

        return $parts === [] ? __('No location set') : implode(' / ', $parts);
    }
};
?>

<div class="space-y-5" x-data="{ reshoot() { $refs.photoInput.value = ''; $refs.photoInput.click(); } }">
    <header class="pt-2">
        <h1 class="text-lg font-semibold text-neutral-100">{{ __('Photo inventory') }}</h1>
        <p class="mt-1 text-xs text-neutral-500">{{ __('Snap each item. Describe them later on desktop.') }}</p>
    </header>

    {{-- Sticky location --}}
    <section aria-label="{{ __('Location applied to every photo') }}"
             class="space-y-2 rounded-2xl border {{ $stickyProperty || $stickyRoom || $stickyContainer ? 'border-emerald-900/60 bg-emerald-950/20' : 'border-neutral-800 bg-neutral-900/40' }} px-3 py-2">
        <div class="flex items-center justify-between gap-3 text-xs">
            <div class="min-w-0 flex-1">
                <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Location') }}</div>
                <div class="truncate {{ $stickyProperty || $stickyRoom || $stickyContainer ? 'text-emerald-200' : 'text-neutral-400' }}">
                    {{ $this->locationSummary }}
                </div>
            </div>
            <button type="button" wire:click="toggleLocation"
                    class="shrink-0 rounded-md border border-neutral-700 bg-neutral-900 px-2 py-1 text-[11px] text-neutral-200 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ $editLocation ? __('Done') : __('Edit') }}
            </button>
        </div>
        @if ($editLocation)
            <div class="grid gap-2 pt-1 text-xs">
                <label class="flex flex-col gap-1">
                    <span class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Property') }}</span>
                    <select wire:model.live="stickyProperty"
                            class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <option value="">—</option>
                        @foreach ($this->properties as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Room') }}</span>
                    <input type="text" wire:model.live.debounce.400ms="stickyRoom"
                           placeholder="{{ __('e.g. Garage, Kitchen…') }}"
                           class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Container') }}</span>
                    <input type="text" wire:model.live.debounce.400ms="stickyContainer"
                           placeholder="{{ __('e.g. Shelf A, Blue bin…') }}"
                           class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                </label>
                @if ($stickyProperty || $stickyRoom || $stickyContainer)
                    <button type="button" wire:click="clearLocation"
                            class="self-start rounded-md px-2 py-1 text-[11px] text-neutral-400 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        {{ __('Clear location') }}
                    </button>
                @endif
            </div>
        @endif
    </section>

    @if ($savedCount > 0)
        <div class="rounded-lg border border-emerald-900/60 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-300" role="status" aria-live="polite">
            {{ trans_choice('{0} Nothing saved yet|{1} :count item saved|[2,*] :count items saved', $savedCount, ['count' => $savedCount]) }}
        </div>
    @endif

    <div class="space-y-3">
        @if ($photo && method_exists($photo, 'isPreviewable') && $photo->isPreviewable())
            <figure class="overflow-hidden rounded-2xl border border-neutral-800 bg-neutral-900">
                <img src="{{ $photo->temporaryUrl() }}"
                     alt="{{ __('Preview') }}"
                     class="block h-auto w-full object-cover" />
            </figure>
        @elseif ($photo)
            <div class="rounded-2xl border border-rose-900/60 bg-rose-900/20 px-3 py-6 text-center text-sm text-rose-300" role="alert">
                {{ __('That file is not an image.') }}
            </div>
        @else
            <label for="photo-input"
                   class="flex aspect-[4/5] w-full cursor-pointer flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-neutral-700 bg-neutral-900/40 text-neutral-400 active:bg-neutral-900/70">
                <svg class="h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 8a2 2 0 0 1 2-2h2.5l1.5-2h6l1.5 2H19a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8Z"/>
                    <circle cx="12" cy="13" r="4"/>
                </svg>
                <span class="text-sm">{{ __('Tap to take a photo') }}</span>
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
        {{ __('Saved items land in your desktop Inventory unprocessed filter.') }}
    </p>
</div>

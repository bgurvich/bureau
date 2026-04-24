<?php

use App\Models\Appointment;
use App\Models\Contract;
use App\Models\Document;
use App\Models\FoodEntry;
use App\Models\InventoryItem;
use App\Models\Media;
use App\Models\PhysicalMail;
use App\Models\Property;
use App\Models\Vehicle;
use App\Support\CurrentHousehold;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Global modal, mounted once in the layout. An inspector's "Browse
 * library" button dispatches media-library-open with {type, id, role}
 * and the modal opens filtered against that target so the user can
 * either pick from existing Media rows or drop new files to upload +
 * attach in one step.
 *
 * The type→model map is a hardcoded whitelist so a stray event can't
 * coerce the modal into attaching media to an arbitrary class.
 */
new class extends Component
{
    use WithFileUploads;

    private const MAX_FILE_MB = 20;

    private const TARGET_MODELS = [
        'inventory' => InventoryItem::class,
        'document' => Document::class,
        'vehicle' => Vehicle::class,
        'property' => Property::class,
        'contract' => Contract::class,
        'appointment' => Appointment::class,
        'physical_mail' => PhysicalMail::class,
        'food_entry' => FoodEntry::class,
    ];

    public bool $open = false;

    public string $targetType = '';

    public ?int $targetId = null;

    public string $role = 'photo';

    public string $search = '';

    public string $mimeFilter = '';

    public string $sort = 'newest';

    /** @var array<int, int> */
    public array $selectedIds = [];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    public string $notice = '';

    #[On('media-library-open')]
    public function show(string $type = '', ?int $id = null, string $role = 'photo'): void
    {
        if (! array_key_exists($type, self::TARGET_MODELS)) {
            return;
        }
        $this->targetType = $type;
        $this->targetId = $id;
        $this->role = $role;
        $this->search = '';
        $this->mimeFilter = '';
        $this->selectedIds = [];
        $this->uploads = [];
        $this->notice = '';
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->search = '';
        $this->selectedIds = [];
        $this->uploads = [];
        $this->notice = '';
    }

    public function toggleSelect(int $id): void
    {
        if (in_array($id, $this->selectedIds, true)) {
            $this->selectedIds = array_values(array_filter($this->selectedIds, fn ($v) => $v !== $id));
        } else {
            $this->selectedIds[] = $id;
        }
    }

    /**
     * Upload handler — runs when the user drops files or picks them.
     * Files are stored on the local disk; a Media row is created for
     * each and the id is added to selectedIds so the user can still
     * review the selection before attaching.
     */
    public function updatedUploads(): void
    {
        $householdId = CurrentHousehold::id();
        if ($householdId === null || $this->uploads === []) {
            return;
        }
        $dir = 'uploads/'.$householdId.'/'.now()->format('Y/m');

        foreach ($this->uploads as $upload) {
            try {
                $upload->validate(['file' => 'required|max:'.(self::MAX_FILE_MB * 1024)]);
            } catch (\Throwable) {
                continue;
            }
            $path = $upload->store($dir, 'local');
            if (! $path) {
                continue;
            }
            $media = Media::create([
                'disk' => 'local',
                'path' => $path,
                'original_name' => $upload->getClientOriginalName(),
                'mime' => $upload->getMimeType(),
                'size' => $upload->getSize(),
            ]);
            $this->selectedIds[] = (int) $media->id;
        }
        $this->uploads = [];
    }

    /**
     * Resolve the target record from (type, id) and attach the selected
     * media via the polymorphic mediables pivot. Position is appended
     * to the end so existing covers + ordering are preserved.
     */
    public function attachSelected(): void
    {
        $class = self::TARGET_MODELS[$this->targetType] ?? null;
        if ($class === null || $this->targetId === null || $this->selectedIds === []) {
            return;
        }

        $model = $class::find($this->targetId);
        if ($model === null || ! method_exists($model, 'media')) {
            return;
        }

        $existing = $model->media()->count();
        $rows = [];
        foreach (array_values(array_unique($this->selectedIds)) as $idx => $mid) {
            $rows[(int) $mid] = ['role' => $this->role, 'position' => $existing + $idx];
        }
        $model->media()->syncWithoutDetaching($rows);

        $this->notice = __(':n attached', ['n' => count($rows)]);
        $this->selectedIds = [];
        $this->dispatch('media-attached', type: $this->targetType, id: $this->targetId);
        $this->dispatch('inspector-saved'); // refresh other UI
    }

    /** @return Collection<int, Media> */
    #[Computed]
    public function library(): Collection
    {
        $q = Media::query();
        if ($this->search !== '') {
            $q->where('original_name', 'like', '%'.$this->search.'%');
        }
        if ($this->mimeFilter !== '') {
            $q->where('mime', 'like', $this->mimeFilter.'%');
        }

        $q = match ($this->sort) {
            'name' => $q->orderBy('original_name'),
            'largest' => $q->orderByDesc('size'),
            default => $q->orderByDesc('created_at'),
        };

        return $q->limit(60)->get(['id', 'disk', 'path', 'original_name', 'mime', 'size', 'captured_at', 'created_at']);
    }
};
?>

<div x-data="{
        open: @entangle('open').live,
        dragOver: false,
        dropFiles(evt) {
            this.dragOver = false;
            const files = Array.from(evt.dataTransfer?.files ?? []);
            if (! files.length) return;
            const input = this.$refs.uploadInput;
            const dt = new DataTransfer();
            for (const f of files) dt.items.add(f);
            input.files = dt.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
     }"
     x-cloak
     x-show="open"
     @keydown.escape.window="$wire.close()"
     role="dialog"
     aria-modal="true"
     aria-labelledby="mlm-title"
     class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8">
    <div x-show="open"
         x-transition.opacity.duration.150ms
         @click="$wire.close()"
         class="fixed inset-0 bg-neutral-950/70"
         aria-hidden="true"></div>

    <div x-show="open"
         x-transition.opacity.duration.150ms
         @dragover.prevent="dragOver = true"
         @dragleave.prevent="dragOver = false"
         @drop.prevent="dropFiles($event)"
         class="relative z-10 flex max-h-[calc(100vh-4rem)] w-full max-w-3xl flex-col overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900 shadow-2xl"
         :class="dragOver ? 'ring-2 ring-emerald-500/60 ring-offset-2 ring-offset-neutral-950' : ''">
        <header class="flex items-baseline justify-between border-b border-neutral-800 px-5 py-3">
            <h2 id="mlm-title" class="text-sm font-semibold text-neutral-100">{{ __('Media library') }}</h2>
            <button type="button" wire:click="close"
                    aria-label="{{ __('Close') }}"
                    class="text-neutral-500 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">×</button>
        </header>

        <div class="flex flex-wrap items-end gap-3 border-b border-neutral-800 px-5 py-3">
            <div>
                <label for="mlm-search" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="mlm-search" type="search"
                       placeholder="{{ __('Filename…') }}"
                       class="mt-1 w-52 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            </div>
            <div>
                <label for="mlm-mime" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Kind') }}</label>
                <select wire:model.live="mimeFilter" id="mlm-mime"
                        class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <option value="">{{ __('Any') }}</option>
                    <option value="image/">{{ __('Images') }}</option>
                    <option value="application/pdf">{{ __('PDFs') }}</option>
                </select>
            </div>
            <div>
                <label for="mlm-sort" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Sort') }}</label>
                <select wire:model.live="sort" id="mlm-sort"
                        class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <option value="newest">{{ __('Newest first') }}</option>
                    <option value="name">{{ __('Name') }}</option>
                    <option value="largest">{{ __('Largest first') }}</option>
                </select>
            </div>
            <div class="ml-auto flex items-center gap-2">
                <label for="mlm-upload"
                       class="cursor-pointer rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-xs text-neutral-300 hover:border-neutral-500 hover:text-neutral-100 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-neutral-300">
                    {{ __('+ Upload') }}
                </label>
                <input id="mlm-upload" type="file"
                       x-ref="uploadInput"
                       wire:model="uploads"
                       multiple
                       accept="image/*,application/pdf"
                       class="sr-only" />
            </div>
        </div>

        <div class="flex-1 overflow-y-auto px-5 py-4">
            <p class="mb-3 text-[11px] text-neutral-500">
                {{ __('Drop files anywhere on the modal to upload. Click a tile to toggle selection.') }}
            </p>
            @if ($this->library->isEmpty())
                <div class="py-10 text-center text-sm text-neutral-500">
                    {{ __('Nothing matches those filters.') }}
                </div>
            @else
                <ul class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
                    @foreach ($this->library as $m)
                        @php($selected = in_array((int) $m->id, $selectedIds, true))
                        <li>
                            <button type="button"
                                    wire:click="toggleSelect({{ $m->id }})"
                                    class="flex w-full flex-col gap-1 rounded-md border p-1 text-left transition {{ $selected ? 'border-emerald-500 ring-2 ring-emerald-500/30' : 'border-neutral-800 hover:border-neutral-600' }} focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                <span class="block aspect-square overflow-hidden rounded bg-neutral-950">
                                    @if (str_starts_with((string) $m->mime, 'image/'))
                                        <img src="{{ route('media.file', $m) }}" alt="" class="h-full w-full object-cover" loading="lazy" />
                                    @else
                                        <span class="flex h-full w-full items-center justify-center text-[10px] uppercase tracking-wider text-neutral-500">
                                            {{ pathinfo((string) $m->original_name, PATHINFO_EXTENSION) ?: 'FILE' }}
                                        </span>
                                    @endif
                                </span>
                                <span class="block truncate text-[11px] text-neutral-400">{{ $m->original_name ?: __('(untitled)') }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <footer class="flex items-center justify-between gap-3 border-t border-neutral-800 bg-neutral-950/60 px-5 py-3">
            <div class="flex flex-wrap items-baseline gap-3">
                <span class="text-[11px] text-neutral-500">
                    {{ __(':n selected', ['n' => count($selectedIds)]) }}
                </span>
                @if ($notice !== '')
                    <span role="status" class="text-[11px] text-emerald-300">{{ $notice }}</span>
                @endif
                <span wire:loading wire:target="uploads" class="text-[11px] text-neutral-500">{{ __('Uploading…') }}</span>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <button type="button" wire:click="close"
                        class="rounded-md border border-neutral-800 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-300 hover:border-neutral-600 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Close') }}
                </button>
                <button type="button"
                        wire:click="attachSelected"
                        @disabled(count($selectedIds) === 0)
                        class="rounded-md border border-emerald-700/50 bg-emerald-900/30 px-3 py-1.5 text-xs font-medium text-emerald-200 hover:bg-emerald-900/50 disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Attach :n', ['n' => count($selectedIds)]) }}
                </button>
            </div>
        </footer>
    </div>
</div>

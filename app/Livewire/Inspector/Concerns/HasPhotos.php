<?php

declare(strict_types=1);

namespace App\Livewire\Inspector\Concerns;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Livewire\WithFileUploads;

/**
 * Photos repeater for inspector child components whose backing model
 * uses the HasMedia trait. Reuses the shared
 * `partials/inspector/fields/photos.blade.php` include — the form
 * just needs to host this trait + adminModelMap() (via HasAdminPanel)
 * and every method the partial reads is here.
 *
 * Uses the pivot `role = photo` + `position` convention so the first
 * slot is always the cover in drill-down lists. Drag-reorder commits
 * through reorderPhotos(); new uploads append at the end.
 *
 * Forms that want photo-first creation (e.g. inventory) override
 * ensureDraftForPhoto() to stamp a minimal record so the user can
 * attach media before filling the rest of the form.
 */
trait HasPhotos
{
    use WithFileUploads;

    /** @var array<int, UploadedFile> */
    public array $photoUpload = [];

    public function updatedPhotoUpload(): void
    {
        if (! empty($this->photoUpload)) {
            $this->addPhoto();
        }
    }

    public function addPhoto(): void
    {
        [$class] = $this->adminModelMap();
        if (! $class || ! method_exists($class, 'media') || empty($this->photoUpload)) {
            return;
        }

        $this->validate(['photoUpload.*' => 'required|image|max:20480']);

        // Create-mode: no record exists yet. Stamp a minimal draft so we
        // have an id to attach against. The child's in-progress form state
        // gets applied when they click Save, which becomes an update.
        if (! $this->id) {
            $this->ensureDraftForPhoto();
        }
        if (! $this->id) {
            return;
        }

        /** @var Model|null $model */
        $model = $class::find($this->id);
        if (! $model) {
            return;
        }

        $relation = call_user_func([$model, 'media']);
        $position = (int) $relation->wherePivot('role', 'photo')->max('position');

        foreach ($this->photoUpload as $file) {
            $originalName = $file->getClientOriginalName();
            $mime = $file->getMimeType();
            $size = $file->getSize();
            $path = $file->store('inspector-uploads', 'local');

            $media = Media::create([
                'disk' => 'local',
                'path' => $path,
                'original_name' => $originalName,
                'mime' => $mime,
                'size' => $size,
                'captured_at' => now(),
                'ocr_status' => 'skip',
            ]);

            $position++;
            call_user_func([$model, 'media'])->attach($media->id, ['role' => 'photo', 'position' => $position]);
        }

        $this->reset('photoUpload');
    }

    public function deletePhoto(int $mediaId): void
    {
        [$class] = $this->adminModelMap();
        if (! $class || ! $this->id || ! method_exists($class, 'media')) {
            return;
        }

        /** @var Model|null $model */
        $model = $class::find($this->id);
        if (! $model) {
            return;
        }

        call_user_func([$model, 'media'])->detach($mediaId);
    }

    /**
     * @param  array<int, int|string>  $orderedIds
     */
    public function reorderPhotos(array $orderedIds): void
    {
        [$class] = $this->adminModelMap();
        if (! $class || ! $this->id || ! method_exists($class, 'media')) {
            return;
        }

        /** @var Model|null $model */
        $model = $class::find($this->id);
        if (! $model) {
            return;
        }

        $relation = call_user_func([$model, 'media']);
        foreach (array_values($orderedIds) as $position => $mediaId) {
            $relation->updateExistingPivot((int) $mediaId, ['position' => $position]);
        }
    }

    /**
     * @return Collection<int, Media>
     */
    public function inspectorPhotos(): Collection
    {
        [$class] = $this->adminModelMap();
        if (! $class || ! $this->id || ! method_exists($class, 'media')) {
            /** @var Collection<int, Media> $empty */
            $empty = collect();

            return $empty;
        }

        /** @var Model|null $model */
        $model = $class::find($this->id);
        if (! $model) {
            /** @var Collection<int, Media> $empty */
            $empty = collect();

            return $empty;
        }

        /** @var Collection<int, Media> $photos */
        $photos = call_user_func([$model, 'media'])
            ->wherePivot('role', 'photo')
            ->orderByPivot('position')
            ->orderBy('media.created_at')
            ->get();

        return $photos;
    }

    /**
     * Override in the child form to stamp a minimal record when the
     * user uploads a photo before saving. Default is a no-op — most
     * types don't support photo-first creation, so the upload is
     * silently dropped in that case.
     */
    protected function ensureDraftForPhoto(): void
    {
        // no-op
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Inspector\Concerns;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

/**
 * Shared tag-input behavior for inspector child components. Mirrors the
 * pre-extraction shell methods: load reads model.tags → space-joined
 * $tag_list; save parses the string (space/comma separators, optional
 * `#` prefix), first-or-creates each Tag, and syncs the pivot. Uses
 * adminModelMap() from HasAdminPanel to know which class backs the
 * record; types whose model has no `tags()` relation silently skip.
 */
trait HasTagList
{
    public string $tag_list = '';

    protected function loadTagList(): void
    {
        if (! $this->id) {
            return;
        }

        [$class] = $this->adminModelMap();
        if (! $class || ! method_exists($class, 'tags')) {
            return;
        }

        /** @var Model|null $model */
        $model = $class::with('tags:id,name')->find($this->id);
        if (! $model) {
            return;
        }

        /** @var EloquentCollection<int, Tag> $tags */
        $tags = $model->getRelation('tags');
        $this->tag_list = $tags->pluck('name')->implode(' ');
    }

    /** @return array<int, string> */
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

    protected function persistTagList(): void
    {
        [$class] = $this->adminModelMap();
        if (! $class || ! $this->id || ! method_exists($class, 'tags')) {
            return;
        }

        /** @var Model|null $model */
        $model = $class::find($this->id);
        if (! $model) {
            return;
        }

        $names = $this->parseTagList($this->tag_list);
        $ids = [];
        foreach ($names as $name) {
            $slug = Str::slug($name);
            $tag = Tag::firstOrCreate(['slug' => $slug], ['name' => $name]);
            $ids[] = $tag->id;
        }

        /** @var MorphToMany<Tag, Model> $relation */
        $relation = call_user_func([$model, 'tags']);
        $relation->sync($ids);
    }
}

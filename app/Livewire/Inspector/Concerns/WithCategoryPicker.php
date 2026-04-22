<?php

declare(strict_types=1);

namespace App\Livewire\Inspector\Concerns;

use App\Models\Category;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;

/**
 * Shared category-picker helpers for inspector child components that render
 * `<x-ui.searchable-select>` with inline create. The child owns its own
 * copy so the picker's `ss-option-added` events land back on the child
 * (not on the parent shell which no longer holds the property).
 */
trait WithCategoryPicker
{
    /** @return array<int, string> */
    #[Computed]
    public function categoryPickerOptions(): array
    {
        return Category::orderBy('name')->pluck('name', 'id')->all();
    }

    public function createCategoryInline(string $name, ?string $modelKey = null): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        $slug = Str::slug($name);
        $base = $slug === '' ? 'cat-'.bin2hex(random_bytes(3)) : $slug;
        $suffix = 0;
        while (Category::where('slug', $suffix ? "{$base}-{$suffix}" : $base)->exists()) {
            $suffix++;
        }
        $category = Category::create([
            'name' => $name,
            'slug' => $suffix ? "{$base}-{$suffix}" : $base,
            'kind' => 'expense',
        ]);

        unset($this->categoryPickerOptions);

        $label = ucfirst($category->kind).' · '.$category->name;
        $this->dispatch('ss-option-added', model: $modelKey ?: $this->defaultCategoryPickerModel(), id: $category->id, label: $label);
    }

    protected function defaultCategoryPickerModel(): string
    {
        return 'category_id';
    }
}

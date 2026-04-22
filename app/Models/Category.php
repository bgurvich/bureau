<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Disambiguated picker label that includes the parent segment when
     * present, so hierarchical siblings like `pets/grooming` and
     * `personal/grooming` don't both render as plain "Grooming". Kind
     * prefix is optional — on contexts where every visible category is
     * the same kind (tag-rule picker, etc.) the kind adds noise.
     */
    public function displayLabel(bool $includeKind = false): string
    {
        $parts = [];
        if ($includeKind && is_string($this->kind) && $this->kind !== '') {
            $parts[] = ucfirst($this->kind);
        }
        $parent = $this->parent?->name;
        if (is_string($parent) && $parent !== '') {
            $parts[] = $parent;
        }
        $parts[] = (string) $this->name;

        return implode(' · ', $parts);
    }
}

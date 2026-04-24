<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nested physical location. Tree walks go through parent_id; a root
 * location has parent_id = null. A location may be anchored to a
 * Property (the house it lives in) or floating (storage unit, car).
 */
class Location extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'position' => 'integer',
    ];

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    /** @return HasMany<Location, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_id')->orderBy('position');
    }

    /** @return HasMany<InventoryItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    /**
     * Walks upward to the root, collecting {root, …, self} in order.
     * Cheap for typical depths (≤ 5) and guards against corrupt cycles
     * via a visited-set.
     *
     * @return Collection<int, Location>
     */
    public function ancestors(): Collection
    {
        $out = new Collection;
        $visited = [];
        $current = $this;
        while ($current !== null) {
            if (isset($visited[$current->id])) {
                break;
            }
            $visited[$current->id] = true;
            $out->prepend($current);
            $current = $current->parent;
        }

        return $out;
    }

    /**
     * Breadcrumb string like "House › Office › Desk Drawer". Separator
     * is a thin-space-padded chevron so it wraps gracefully on mobile.
     */
    public function breadcrumb(string $separator = ' › '): string
    {
        return $this->ancestors()->pluck('name')->implode($separator);
    }

    /**
     * Transitive descendant ids (including self). Used by delete and
     * move operations that need to reject reparenting into a subtree.
     *
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        $ids = [(int) $this->id];
        $queue = [(int) $this->id];
        while ($queue !== []) {
            $batch = static::query()->whereIn('parent_id', $queue)->pluck('id')->all();
            $queue = [];
            foreach ($batch as $id) {
                $id = (int) $id;
                if (in_array($id, $ids, true)) {
                    continue;
                }
                $ids[] = $id;
                $queue[] = $id;
            }
        }

        return $ids;
    }
}

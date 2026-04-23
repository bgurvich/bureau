<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasTags;
use App\Support\ChecklistScheduling;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistTemplate extends Model
{
    use BelongsToHousehold, HasTags;

    protected $guarded = [];

    protected $casts = [
        'dtstart' => 'date',
        'paused_until' => 'date',
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * A template is a habit if it has a recurring rrule — anything that
     * isn't empty and isn't a single-occurrence COUNT=1. Covers
     * FREQ=DAILY, weekly, monthly, custom RRULEs with BYDAY/BYMONTH,
     * etc. Morning/evening routines with multiple items are still
     * habits; item count isn't the distinction.
     */
    public function isHabit(): bool
    {
        $rrule = trim((string) $this->rrule);
        if ($rrule === '') {
            return false;
        }

        return ! str_contains(strtoupper($rrule), 'COUNT=1');
    }

    /** Inverse of isHabit(): one-off checklists (shopping, packing, onboarding). */
    public function isOneOff(): bool
    {
        return ! $this->isHabit();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ChecklistTemplateItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ChecklistTemplateItem::class)->orderBy('position');
    }

    /** @return HasMany<ChecklistRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ChecklistRun::class);
    }

    /** @return array<int, int> */
    public function activeItemIds(): array
    {
        return $this->items
            ->where('active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function isScheduledOn(CarbonInterface|DateTimeInterface|string $date): bool
    {
        return ChecklistScheduling::isScheduledOn($this, $date);
    }

    public function streak(?CarbonInterface $today = null): int
    {
        return ChecklistScheduling::streak($this, $today);
    }
}

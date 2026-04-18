<?php

namespace App\Models;

use App\Exceptions\PeriodLockedException;
use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodLock extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'locked_through' => 'date',
        'locked_at' => 'datetime',
        'unlocked_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_user_id');
    }

    /**
     * The current active lock-through date for the given household,
     * or null if nothing is locked. Must be called with CurrentHousehold set
     * (the global scope will filter by it).
     */
    public static function currentLockedThrough(): ?CarbonInterface
    {
        /** @var self|null $row */
        $row = self::query()
            ->whereNull('unlocked_at')
            ->orderByDesc('locked_through')
            ->first();

        return $row?->locked_through;
    }

    /**
     * Throw if a write targeting $date lands on or before the active lock.
     * Null dates pass through (nothing to guard).
     */
    public static function assertWritable(CarbonInterface|string|null $date): void
    {
        if ($date === null || $date === '') {
            return;
        }

        $locked = self::currentLockedThrough();
        if ($locked === null) {
            return;
        }

        $attempted = $date instanceof CarbonInterface
            ? $date
            : CarbonImmutable::parse($date);

        if ($attempted->lte($locked)) {
            throw new PeriodLockedException($attempted, $locked);
        }
    }
}

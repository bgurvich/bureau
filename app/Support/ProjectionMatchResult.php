<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\RecurringProjection;

/**
 * Outcome of `ProjectionMatcher::resolve()`. Three mutually exclusive shapes:
 *
 *   • linked       → exactly one projection matched, already linked to the
 *                    transaction by the matcher. `candidates` is empty.
 *   • ambiguous    → multiple projections would legitimately match; the
 *                    matcher refused to guess. Caller should show a picker.
 *   • miss         → no projection matched and fuzzy fallback didn't
 *                    identify a rule either. Transaction stays standalone.
 *
 * Kept separate from the matcher class so controllers and Livewire components
 * can depend on the result shape without pulling in the matching algorithm.
 */
final readonly class ProjectionMatchResult
{
    /**
     * @param  array<int, RecurringProjection>  $candidates
     */
    public function __construct(
        public ?RecurringProjection $linked = null,
        public array $candidates = [],
    ) {}

    public static function linked(RecurringProjection $projection): self
    {
        return new self(linked: $projection);
    }

    /**
     * @param  array<int, RecurringProjection>  $candidates
     */
    public static function ambiguous(array $candidates): self
    {
        return new self(candidates: array_values($candidates));
    }

    public static function miss(): self
    {
        return new self;
    }

    public function isLinked(): bool
    {
        return $this->linked !== null;
    }

    public function isAmbiguous(): bool
    {
        return $this->linked === null && count($this->candidates) >= 2;
    }

    public function isMiss(): bool
    {
        return $this->linked === null && count($this->candidates) < 2;
    }
}

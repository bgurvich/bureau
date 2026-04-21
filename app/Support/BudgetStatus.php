<?php

namespace App\Support;

use App\Models\BudgetCap;

/**
 * A single envelope's month-to-date state — amount spent, utilization, and
 * the tier (ok / warning / over) the radar and /budgets page use to pick a
 * color. Returned as a readonly value object instead of an anonymous
 * stdClass so PHPStan can type the BudgetMonitor return Collection cleanly.
 */
final class BudgetStatus
{
    public function __construct(
        public readonly BudgetCap $cap,
        public readonly float $spent,
        public readonly float $ratio,
        public readonly string $state,
    ) {}
}

<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

class PeriodLockedException extends RuntimeException
{
    public function __construct(
        public readonly CarbonInterface $attemptedDate,
        public readonly CarbonInterface $lockedThrough,
    ) {
        parent::__construct(sprintf(
            'Write to %s is blocked by a period lock through %s. Unlock the period before editing.',
            $attemptedDate->toDateString(),
            $lockedThrough->toDateString(),
        ));
    }
}

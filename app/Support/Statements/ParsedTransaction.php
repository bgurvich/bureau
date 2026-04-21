<?php

namespace App\Support\Statements;

use Carbon\CarbonImmutable;

/**
 * A single row extracted from a statement — bank-agnostic. Describes what
 * the ledger change is, not how the row looked in the source.
 */
final class ParsedTransaction
{
    public function __construct(
        public readonly CarbonImmutable $occurredOn,
        public readonly string $description,
        public readonly float $amount,               // signed — negative = debit
        public readonly ?float $runningBalance = null,
        public readonly ?string $rawRow = null,      // original line/row for debugging
    ) {}
}

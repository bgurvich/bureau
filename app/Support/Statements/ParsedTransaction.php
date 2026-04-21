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
        // Bank-specific flag that sits between date and description — on
        // WF checking it holds either a paper-check number or a legend
        // marker ("<" for "Business to Business ACH", etc.). Null when
        // the column is absent (most CSV sources) or empty (most rows).
        public readonly ?string $checkNumber = null,
    ) {}
}

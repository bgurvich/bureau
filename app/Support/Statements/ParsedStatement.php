<?php

namespace App\Support\Statements;

use Carbon\CarbonImmutable;

/**
 * Everything a parser knows about one statement after reading the source
 * file. Consumed by the review UI — the UI has no idea which parser or
 * bank produced this; it just renders the DTO.
 */
final class ParsedStatement
{
    /**
     * @param  array<int, ParsedTransaction>  $transactions
     */
    public function __construct(
        public readonly string $bankSlug,            // e.g. "wellsfargo_checking"
        public readonly string $bankLabel,           // e.g. "Wells Fargo — checking"
        public readonly ?string $accountLast4,
        public readonly ?CarbonImmutable $periodStart,
        public readonly ?CarbonImmutable $periodEnd,
        public readonly ?float $openingBalance,
        public readonly ?float $closingBalance,
        public readonly array $transactions,
    ) {}

    public function importSource(): string
    {
        return 'statement:'.$this->bankSlug;
    }
}

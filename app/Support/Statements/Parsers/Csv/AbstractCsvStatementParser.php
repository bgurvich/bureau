<?php

namespace App\Support\Statements\Parsers\Csv;

use App\Support\Statements\ParsedStatement;
use App\Support\Statements\ParsedTransaction;
use App\Support\Statements\StatementParser;

/**
 * Base for bank CSV parsers. Handles the uniform skeleton:
 *   - supports() is always 'csv'
 *   - parse() normalizes the input, iterates rows through mapRow(),
 *     filters nulls, derives period start/end, wraps in a ParsedStatement.
 *
 * Concrete parsers only declare:
 *   - slug() + label() — identity shown to the user and persisted as
 *     import_source.
 *   - fingerprint() — cheap header match deciding whether this parser owns
 *     the file.
 *   - mapRow() — bank-specific column names + sign convention; returns null
 *     to skip a row (bad date, missing amount).
 */
abstract class AbstractCsvStatementParser implements StatementParser
{
    use CsvParserHelpers;

    abstract protected function slug(): string;

    abstract protected function label(): string;

    /**
     * @param  array<string, string>  $row
     */
    abstract protected function mapRow(array $row): ?ParsedTransaction;

    public function supports(string $format): bool
    {
        return $format === 'csv';
    }

    public function parse(string|array $content): ParsedStatement
    {
        $normalized = is_array($content) ? $content : ['headers' => [], 'rows' => []];
        /** @var array<int, array<string, string>> $rows */
        $rows = $normalized['rows'] ?? [];

        $transactions = [];
        foreach ($rows as $row) {
            $t = $this->mapRow($row);
            if ($t !== null) {
                $transactions[] = $t;
            }
        }

        $dates = array_map(fn (ParsedTransaction $t) => $t->occurredOn, $transactions);
        usort($dates, fn ($a, $b) => $a <=> $b);

        return new ParsedStatement(
            bankSlug: $this->slug(),
            bankLabel: $this->label(),
            accountLast4: null,
            periodStart: $dates[0] ?? null,
            periodEnd: end($dates) ?: null,
            openingBalance: null,
            closingBalance: null,
            transactions: $transactions,
        );
    }
}

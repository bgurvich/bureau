<?php

namespace App\Support\Statements\Parsers\Csv;

use App\Support\Statements\ParsedStatement;
use App\Support\Statements\ParsedTransaction;

/**
 * Wells Fargo checking CSV. Their tool emits a headerless 5-column CSV
 * (Date, Amount, *, *, Description) but the web download usually has
 * headers. This parser handles both flavours.
 *
 * Overrides parse() (vs using the base's mapRow-only path) because
 * headerless input needs to re-treat row 0 as data, which doesn't fit the
 * simple map-each-row model.
 */
final class WellsFargoCheckingCsvParser extends AbstractCsvStatementParser
{
    protected function slug(): string
    {
        return 'wellsfargo_checking';
    }

    protected function label(): string
    {
        return 'Wells Fargo — Checking (CSV)';
    }

    public function fingerprint(string|array $content): bool
    {
        if (! is_array($content)) {
            return false;
        }
        $headers = $content['headers'] ?? [];
        if ($this->headersMatch($headers, ['Date', 'Amount']) && ! $this->headersMatch($headers, ['Debit', 'Credit'])) {
            return true;
        }
        // Headerless — 5 columns, first is date-ish, second is money-ish.
        if ($headers !== [] && count($headers) === 5 && $this->date($headers[0] ?? null) && $this->money($headers[1] ?? null)) {
            return true;
        }

        return false;
    }

    public function parse(string|array $content): ParsedStatement
    {
        $normalized = is_array($content) ? $content : ['headers' => [], 'rows' => []];
        $headers = $normalized['headers'] ?? [];
        $rows = $normalized['rows'] ?? [];

        $transactions = [];
        $headerless = $headers !== [] && $this->date($headers[0] ?? null) !== null;
        if ($headerless) {
            $transactions[] = $this->mapRow([
                'Date' => $headers[0] ?? '',
                'Amount' => $headers[1] ?? '',
                'Description' => $headers[4] ?? '',
            ]);
        }
        foreach ($rows as $row) {
            $normalizedRow = $headerless
                ? ['Date' => $row[$headers[0]] ?? '', 'Amount' => $row[$headers[1]] ?? '', 'Description' => $row[$headers[4]] ?? '']
                : $row;
            $t = $this->mapRow($normalizedRow);
            if ($t !== null) {
                $transactions[] = $t;
            }
        }
        $transactions = array_values(array_filter($transactions));

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

    protected function mapRow(array $row): ?ParsedTransaction
    {
        $date = $this->date($this->cell($row, ['Date', 'Transaction Date', 'Post Date']));
        $amount = $this->money($this->cell($row, ['Amount']));
        if ($date === null || $amount === null) {
            return null;
        }

        return new ParsedTransaction(
            occurredOn: $date,
            description: $this->cell($row, ['Description', 'Memo', 'Payee']) ?? '',
            amount: $amount,   // already signed
            rawRow: json_encode($row) ?: null,
        );
    }
}

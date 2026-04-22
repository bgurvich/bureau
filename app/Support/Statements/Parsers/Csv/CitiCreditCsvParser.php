<?php

namespace App\Support\Statements\Parsers\Csv;

use App\Support\Statements\ParsedTransaction;

/**
 * Citi-issued credit card CSV — covers both the vanilla Citi export
 * (Status, Date, Description, Debit, Credit) and the Costco Anywhere
 * Visa "Annual Account Summary" / "Year to date" export, which is also
 * Citi-issued and shares the same columns plus a Category field in
 * place of Status. Both use Citi's Debit/Credit sign convention:
 *
 *   - Debit column carries a positive dollar value for a charge.
 *   - Credit column carries the refund/payment amount — positive on
 *     the vanilla Citi export, sometimes negative on the Costco export
 *     (it encodes the sign of the balance delta rather than the
 *     absolute refund amount). abs() normalizes either shape.
 *
 * Bureau's credit-card convention is charges negative / refunds-and-
 * payments positive, which is what we emit.
 *
 * The Category column (Costco-only) is passed through as a
 * `categoryHint` on ParsedTransaction so the import step can map it to
 * a household category via `categories.match_patterns` without baking
 * Costco's taxonomy into the transaction record itself.
 */
final class CitiCreditCsvParser extends AbstractCsvStatementParser
{
    protected function slug(): string
    {
        return 'citi_credit';
    }

    protected function label(): string
    {
        return 'Citi — Credit Card (CSV)';
    }

    public function fingerprint(string|array $content): bool
    {
        if (! is_array($content)) {
            return false;
        }
        $headers = $content['headers'] ?? [];
        if (! $this->headersMatch($headers, ['Date', 'Description', 'Debit', 'Credit'])) {
            return false;
        }

        // Citi's own export has Status; Costco's has Category. One of the
        // two must be present so we don't swallow arbitrary 4-column
        // Date/Description/Debit/Credit CSVs that aren't Citi-issued.
        return $this->headersMatch($headers, ['Status'])
            || $this->headersMatch($headers, ['Category']);
    }

    protected function mapRow(array $row): ?ParsedTransaction
    {
        $date = $this->date($this->cell($row, ['Date']));
        if ($date === null) {
            return null;
        }
        $debit = $this->money($this->cell($row, ['Debit']));
        $credit = $this->money($this->cell($row, ['Credit']));

        $amount = match (true) {
            $debit !== null && $debit !== 0.0 => -abs($debit),
            $credit !== null && $credit !== 0.0 => abs($credit),
            default => null,
        };
        if ($amount === null) {
            return null;
        }

        return new ParsedTransaction(
            occurredOn: $date,
            description: $this->cell($row, ['Description']) ?? '',
            amount: $amount,
            rawRow: json_encode($row) ?: null,
            categoryHint: $this->cell($row, ['Category']),
        );
    }
}

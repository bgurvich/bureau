<?php

namespace App\Support\Statements\Parsers\Csv;

use App\Support\Statements\ParsedTransaction;

/**
 * Wells Fargo credit card CSV. Mirrors the checking shape (Date + Amount
 * + Description); distinguished by presence of a Card or Type column that
 * the checking export doesn't carry. Amount is already signed.
 */
final class WellsFargoCreditCsvParser extends AbstractCsvStatementParser
{
    protected function slug(): string
    {
        return 'wellsfargo_credit';
    }

    protected function label(): string
    {
        return 'Wells Fargo — Credit Card (CSV)';
    }

    public function fingerprint(string|array $content): bool
    {
        if (! is_array($content)) {
            return false;
        }
        $headers = $content['headers'] ?? [];

        return $this->headersMatch($headers, ['Date', 'Amount'])
            && $this->headersMatch($headers, ['Card', 'Type']);
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
            description: $this->cell($row, ['Description', 'Memo']) ?? '',
            amount: $amount,
            rawRow: json_encode($row) ?: null,
        );
    }
}

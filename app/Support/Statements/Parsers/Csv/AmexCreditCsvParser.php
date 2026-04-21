<?php

namespace App\Support\Statements\Parsers\Csv;

use App\Support\Statements\ParsedTransaction;

/**
 * Amex credit-card CSV export. Header signature: Date + Description +
 * Amount, plus Category or Card Member (distinguishes from Amex bank CSV).
 * Amex credit CSV sign convention: charges positive, payments/credits
 * negative — stored as the opposite (outflow from the credit account).
 */
final class AmexCreditCsvParser extends AbstractCsvStatementParser
{
    protected function slug(): string
    {
        return 'amex_credit';
    }

    protected function label(): string
    {
        return 'Amex — Credit Card (CSV)';
    }

    public function fingerprint(string|array $content): bool
    {
        if (! is_array($content)) {
            return false;
        }
        $headers = $content['headers'] ?? [];

        return $this->headersMatch($headers, ['Date', 'Description', 'Amount'])
            && ($this->headersMatch($headers, ['Category']) || $this->headersMatch($headers, ['Card Member']));
    }

    protected function mapRow(array $row): ?ParsedTransaction
    {
        $date = $this->date($this->cell($row, ['Date']));
        $amount = $this->money($this->cell($row, ['Amount']));
        if ($date === null || $amount === null) {
            return null;
        }

        return new ParsedTransaction(
            occurredOn: $date,
            description: $this->cell($row, ['Description']) ?? '',
            amount: $amount >= 0 ? -abs($amount) : abs($amount),
            rawRow: json_encode($row) ?: null,
        );
    }
}

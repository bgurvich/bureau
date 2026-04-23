<?php

namespace App\Support\Statements\Parsers\Csv;

use App\Support\Statements\ParsedTransaction;

/**
 * OnPoint CU credit card CSV. Header signature: Date, Description, Amount,
 * Card Number (distinguishes from Amex credit via absence of Category and
 * Card Member). Source Amount is positive for charges; Secretaire stores
 * credit-card charges as negative.
 */
final class OnPointCreditCsvParser extends AbstractCsvStatementParser
{
    protected function slug(): string
    {
        return 'onpoint_credit';
    }

    protected function label(): string
    {
        return 'OnPoint CU — Credit Card (CSV)';
    }

    public function fingerprint(string|array $content): bool
    {
        if (! is_array($content)) {
            return false;
        }
        $headers = $content['headers'] ?? [];

        return $this->headersMatch($headers, ['Date', 'Description', 'Amount'])
            && $this->headersMatch($headers, ['Card Number'])
            && ! $this->headersMatch($headers, ['Category', 'Card Member']);
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

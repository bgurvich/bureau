<?php

namespace App\Support\Statements\Parsers\Csv;

use App\Support\Statements\ParsedTransaction;

/**
 * Citi credit card CSV. Header signature: Status, Date, Description,
 * Debit, Credit. The Status column distinguishes this from Citi checking.
 * Citi emits Debit = charge (positive), Credit = payment/refund (positive);
 * Bureau stores charges negative, payments positive.
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

        return $this->headersMatch($headers, ['Status', 'Date', 'Description'])
            && $this->headersMatch($headers, ['Debit', 'Credit']);
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
            $credit !== null && $credit > 0 => $credit,
            $debit !== null && $debit > 0 => -$debit,
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
        );
    }
}

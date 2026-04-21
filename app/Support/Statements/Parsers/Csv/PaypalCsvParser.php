<?php

namespace App\Support\Statements\Parsers\Csv;

use App\Support\Statements\ParsedTransaction;

/**
 * PayPal activity CSV export (Reports → Activity). Header signature: Date,
 * Time, TimeZone, Name, Type, Status, Currency, Amount, Balance, etc. We
 * prefer Net (fee-adjusted) when available, else Amount. "Balance" rows
 * are bookkeeping artefacts and get skipped.
 */
final class PaypalCsvParser extends AbstractCsvStatementParser
{
    protected function slug(): string
    {
        return 'paypal';
    }

    protected function label(): string
    {
        return 'PayPal';
    }

    public function fingerprint(string|array $content): bool
    {
        if (! is_array($content)) {
            return false;
        }
        $headers = $content['headers'] ?? [];

        return $this->headersMatch($headers, ['Date', 'Time', 'Currency'])
            && ($this->headersMatch($headers, ['Net']) || $this->headersMatch($headers, ['Type', 'Status']));
    }

    protected function mapRow(array $row): ?ParsedTransaction
    {
        $type = $this->cell($row, ['Type']) ?? '';
        if (preg_match('/Balance/i', $type)) {
            return null;
        }
        $date = $this->date($this->cell($row, ['Date']));
        $amount = $this->money($this->cell($row, ['Net', 'Amount']));
        $name = $this->cell($row, ['Name']) ?? '';
        $desc = $name !== '' ? $name : $type;
        if ($date === null || $amount === null || $desc === '') {
            return null;
        }

        return new ParsedTransaction(
            occurredOn: $date,
            description: $desc,
            amount: $amount,
            rawRow: json_encode($row) ?: null,
        );
    }
}

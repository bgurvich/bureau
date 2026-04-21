<?php

namespace App\Support\Statements\Parsers\Csv;

use App\Support\Statements\ParsedTransaction;

/**
 * OnPoint CU checking CSV. Header signature: Date, Description, Debit,
 * Credit, Balance, Check Number (or Reference). Check Number + absence of
 * Status differentiates this from Citi checking.
 */
final class OnPointCheckingCsvParser extends AbstractCsvStatementParser
{
    protected function slug(): string
    {
        return 'onpoint_checking';
    }

    protected function label(): string
    {
        return 'OnPoint CU — Checking (CSV)';
    }

    public function fingerprint(string|array $content): bool
    {
        if (! is_array($content)) {
            return false;
        }
        $headers = $content['headers'] ?? [];

        return $this->headersMatch($headers, ['Date', 'Description', 'Debit', 'Credit'])
            && ($this->headersMatch($headers, ['Check Number']) || $this->headersMatch($headers, ['Reference']))
            && ! $this->headersMatch($headers, ['Status']);
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
            runningBalance: $this->money($this->cell($row, ['Balance'])),
            rawRow: json_encode($row) ?: null,
        );
    }
}

<?php

namespace App\Support\Statements\Parsers\Csv;

use App\Support\Statements\ParsedTransaction;

/**
 * Citi checking/savings CSV. Header signature: Date, Description, Debit,
 * Credit, Balance. Sign determined by which column is populated.
 *
 * Requires `Balance` because the 4-column Date/Description/Debit/Credit
 * subset is shared by Citi Credit (which adds Status) and the Costco
 * Anywhere Visa variant (which adds Category). Without the Balance
 * anchor this parser would win the fingerprint race on credit-card
 * exports and sign-flip them incorrectly.
 */
final class CitiCheckingCsvParser extends AbstractCsvStatementParser
{
    protected function slug(): string
    {
        return 'citi_checking';
    }

    protected function label(): string
    {
        return 'Citi — Checking (CSV)';
    }

    public function fingerprint(string|array $content): bool
    {
        if (! is_array($content)) {
            return false;
        }

        return $this->headersMatch($content['headers'] ?? [], ['Date', 'Description', 'Debit', 'Credit', 'Balance']);
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

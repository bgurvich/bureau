<?php

namespace App\Support\Statements\Parsers\Csv;

use App\Support\Statements\ParsedTransaction;

/**
 * Amex bank (Rewards Checking / HYSA) CSV. Header signature: Date,
 * Description, Amount (signed), Balance. Distinguished from Amex credit
 * CSV by the Balance column + absence of Category.
 */
final class AmexCheckingCsvParser extends AbstractCsvStatementParser
{
    protected function slug(): string
    {
        return 'amex_checking';
    }

    protected function label(): string
    {
        return 'Amex — Checking/Savings (CSV)';
    }

    public function fingerprint(string|array $content): bool
    {
        if (! is_array($content)) {
            return false;
        }
        $headers = $content['headers'] ?? [];

        return $this->headersMatch($headers, ['Date', 'Description', 'Amount', 'Balance'])
            && ! $this->headersMatch($headers, ['Category']);
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
            amount: $amount,
            runningBalance: $this->money($this->cell($row, ['Balance'])),
            rawRow: json_encode($row) ?: null,
        );
    }
}

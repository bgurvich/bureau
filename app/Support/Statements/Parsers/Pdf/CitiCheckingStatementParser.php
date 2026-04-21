<?php

namespace App\Support\Statements\Parsers\Pdf;

use App\Support\Statements\ParsedStatement;
use App\Support\Statements\ParsedTransaction;
use App\Support\Statements\StatementParser;
use Carbon\CarbonImmutable;

/**
 * Citi checking-account statement PDF.
 *
 * Layout cues: "Citi" / "Citibank" + "Statement Period" near the top.
 * Transactions appear with "Date  Description  Debit  Credit  Balance"
 * columns. Debit + Credit columns are separate, so sign is determined by
 * which column the amount lives in.
 */
final class CitiCheckingStatementParser implements StatementParser
{
    use PdfParserHelpers;

    public function supports(string $format): bool
    {
        return $format === 'pdf';
    }

    public function fingerprint(string|array $content): bool
    {
        if (! is_string($content)) {
            return false;
        }

        return (bool) preg_match('/Citi(?:bank)?/i', $content)
            && preg_match('/Statement\s*Period|Account\s*Activity/i', $content)
            && ! preg_match('/Credit\s*Card\s*Statement/i', $content);
    }

    public function parse(string|array $content): ParsedStatement
    {
        $text = (string) $content;

        $last4 = $this->matchFirst('/Account\s*(?:number|ending)[^\d]*(\d{4})(?!\d)/i', $text);
        [$start, $end] = $this->matchPeriod($text);
        $opening = $this->matchMoney('/Opening\s*Balance[^\n]*?\$?\s*([\-0-9,\.]+)/i', $text)
            ?? $this->matchMoney('/Beginning\s*Balance[^\n]*?\$?\s*([\-0-9,\.]+)/i', $text);
        $closing = $this->matchMoney('/Closing\s*Balance[^\n]*?\$?\s*([\-0-9,\.]+)/i', $text)
            ?? $this->matchMoney('/Ending\s*Balance[^\n]*?\$?\s*([\-0-9,\.]+)/i', $text);

        return new ParsedStatement(
            bankSlug: 'citi_checking',
            bankLabel: 'Citi — Checking',
            accountLast4: $last4,
            periodStart: $start,
            periodEnd: $end,
            openingBalance: $opening,
            closingBalance: $closing,
            transactions: $this->extractTransactions($text, $end ? $end->year : (int) date('Y')),
        );
    }

    /**
     * @return array<int, ParsedTransaction>
     */
    private function extractTransactions(string $text, int $assumeYear): array
    {
        $rows = [];
        $lines = preg_split('/\r?\n/', $text) ?: [];
        foreach ($lines as $line) {
            // Line shape: "MM/DD  description   debit  credit  balance"
            // Any of debit/credit/balance may be empty.
            if (preg_match('/^\s*(\d{1,2}\/\d{1,2})\s+(.+?)\s{2,}([\-0-9,\.]+)?\s*(?:\s{2,}([\-0-9,\.]+))?\s*(?:\s{2,}([\-0-9,\.]+))?\s*$/', $line, $m)) {
                $date = $this->parseMonthDay($m[1], $assumeYear);
                if ($date === null) {
                    continue;
                }
                $debit = isset($m[3]) ? self::money($m[3]) : null;
                $credit = isset($m[4]) ? self::money($m[4]) : null;
                // Only treat as transaction row if one of debit/credit has a
                // meaningful amount (balance-only rows skip).
                $amount = null;
                if ($credit !== null && $credit > 0) {
                    $amount = $credit;
                } elseif ($debit !== null && $debit > 0) {
                    $amount = -$debit;
                }
                if ($amount === null) {
                    continue;
                }
                $rows[] = new ParsedTransaction(
                    occurredOn: $date,
                    description: trim($m[2]),
                    amount: $amount,
                    runningBalance: isset($m[5]) ? self::money($m[5]) : null,
                    rawRow: trim($line),
                );
            }
        }

        return $rows;
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private function matchPeriod(string $text): array
    {
        if (preg_match('/Statement\s*Period[^\n]*?(\d{1,2}\/\d{1,2}\/\d{2,4})\s*(?:-|to|through|–|—)\s*(\d{1,2}\/\d{1,2}\/\d{2,4})/is', $text, $m)) {
            return [self::date($m[1]), self::date($m[2])];
        }

        return [null, null];
    }
}

<?php

namespace App\Support\Statements\Parsers\Pdf;

use App\Support\Statements\ParsedStatement;
use App\Support\Statements\ParsedTransaction;
use App\Support\Statements\StatementParser;
use Carbon\CarbonImmutable;

/**
 * Wells Fargo checking-account statement PDF.
 *
 * Layout cues (text-extracted):
 *   "Wells Fargo" + "Statement Period" near the top.
 *   Transactions appear in sections with headers like "Deposits and Other
 *   Additions" (positive) and "Withdrawals and Other Subtractions"
 *   (negative), each followed by rows "MM/DD  description  amount".
 *
 * Robust variants exist across WF checking products — first draft here
 * handles the common layout; iterate with real PDFs in hand.
 */
final class WellsFargoCheckingStatementParser implements StatementParser
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

        return preg_match('/Wells\s*Fargo/i', $content)
            && ! preg_match('/Credit\s*Card\s*Statement/i', $content);
    }

    public function parse(string|array $content): ParsedStatement
    {
        $text = (string) $content;

        $last4 = $this->matchFirst('/Account\s*number[^0-9]*(\d{4})(?!\d)/i', $text)
            ?? $this->matchFirst('/Account\s*ending\s*in\s*(\d{4})/i', $text);

        [$start, $end] = $this->matchPeriod($text);
        $opening = $this->matchMoney('/Beginning\s*balance\s*(?:on\s*\d{1,2}\/\d{1,2}(?:\/\d{2,4})?)?\s*\$?\s*([\-0-9,\.]+)/i', $text);
        $closing = $this->matchMoney('/Ending\s*balance\s*(?:on\s*\d{1,2}\/\d{1,2}(?:\/\d{2,4})?)?\s*\$?\s*([\-0-9,\.]+)/i', $text);

        $transactions = $this->extractTransactions($text, $end ? $end->year : (int) date('Y'));

        return new ParsedStatement(
            bankSlug: 'wellsfargo_checking',
            bankLabel: 'Wells Fargo — Checking',
            accountLast4: $last4,
            periodStart: $start,
            periodEnd: $end,
            openingBalance: $opening,
            closingBalance: $closing,
            transactions: $transactions,
        );
    }

    /**
     * @return array<int, ParsedTransaction>
     */
    private function extractTransactions(string $text, int $assumeYear): array
    {
        $rows = [];
        $credit = false;
        $lines = preg_split('/\r?\n/', $text) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/Deposits\s*and\s*Other\s*Additions/i', $line)) {
                $credit = true;

                continue;
            }
            if (preg_match('/Withdrawals\s*and\s*Other\s*Subtractions|Checks\s*paid|ATM\s*and\s*Debit\s*Card/i', $line)) {
                $credit = false;

                continue;
            }
            // Match "M/D  description  123.45" or "M/D  description  -123.45"
            if (preg_match('/^\s*(\d{1,2}\/\d{1,2})\s+(.+?)\s{2,}([\-0-9,\.]+)\s*$/', $line, $m)) {
                $date = $this->parseMonthDay($m[1], $assumeYear);
                $amount = self::money($m[3]);
                if ($date === null || $amount === null) {
                    continue;
                }
                if (! $credit && $amount > 0) {
                    $amount = -$amount;
                }
                $rows[] = new ParsedTransaction(
                    occurredOn: $date,
                    description: trim($m[2]),
                    amount: $amount,
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

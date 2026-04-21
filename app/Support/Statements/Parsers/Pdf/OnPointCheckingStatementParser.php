<?php

namespace App\Support\Statements\Parsers\Pdf;

use App\Support\Statements\ParsedStatement;
use App\Support\Statements\ParsedTransaction;
use App\Support\Statements\StatementParser;
use Carbon\CarbonImmutable;

/**
 * OnPoint Community Credit Union checking/savings PDF.
 *
 * Layout cues: "OnPoint Community Credit Union" / "OnPoint CCU" header +
 * "Statement Period". Transactions live under "Deposits and Credits" /
 * "Withdrawals and Debits" sections, with "MM/DD  description  amount"
 * rows. Running balance usually not shown inline.
 */
final class OnPointCheckingStatementParser implements StatementParser
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

        return (bool) preg_match('/OnPoint\s*(?:Community\s*)?Credit\s*Union|OnPoint\s*CCU/i', $content)
            && ! preg_match('/Visa|Mastercard|Credit\s*Card\s*Statement/i', $content);
    }

    public function parse(string|array $content): ParsedStatement
    {
        $text = (string) $content;

        $last4 = $this->matchFirst('/Account\s*(?:number|ending)[^\d]*(\d{4})(?!\d)/i', $text);
        [$start, $end] = $this->matchPeriod($text);
        $opening = $this->matchMoney('/(?:Beginning|Opening)\s*Balance[^\n]*?\$?\s*([\-0-9,\.]+)/i', $text);
        $closing = $this->matchMoney('/(?:Ending|Closing)\s*Balance[^\n]*?\$?\s*([\-0-9,\.]+)/i', $text);

        return new ParsedStatement(
            bankSlug: 'onpoint_checking',
            bankLabel: 'OnPoint CU — Checking',
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
        $withdrawalContext = false;
        $lines = preg_split('/\r?\n/', $text) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/Withdrawals|Debits|Fees/i', $line)) {
                $withdrawalContext = true;

                continue;
            }
            if (preg_match('/Deposits|Credits|Dividends|Interest/i', $line)) {
                $withdrawalContext = false;

                continue;
            }
            if (preg_match('/^\s*(\d{1,2}\/\d{1,2})\s+(.+?)\s{2,}\$?\s*([\-0-9,\.]+)\s*$/', $line, $m)) {
                $date = $this->parseMonthDay($m[1], $assumeYear);
                $amount = self::money($m[3]);
                if ($date === null || $amount === null) {
                    continue;
                }
                if ($withdrawalContext && $amount > 0) {
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

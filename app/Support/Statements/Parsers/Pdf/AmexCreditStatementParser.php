<?php

namespace App\Support\Statements\Parsers\Pdf;

use App\Support\Statements\ParsedStatement;
use App\Support\Statements\ParsedTransaction;
use App\Support\Statements\StatementParser;
use Carbon\CarbonImmutable;

/**
 * American Express credit-card statement PDF.
 *
 * Layout cues: "American Express" + "Prepared for" + "Closing Date". Rows
 * land under "New Charges" / "Payments and Credits" with "MM/DD/YY
 * description amount" formatting.
 */
final class AmexCreditStatementParser implements StatementParser
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

        return (bool) preg_match('/American\s*Express/i', $content)
            && preg_match('/(?:Closing\s*Date|Account\s*Ending|Prepared\s*for)/i', $content)
            && ! preg_match('/National\s*Bank|High\s*Yield\s*Savings|Rewards\s*Checking/i', $content);
    }

    public function parse(string|array $content): ParsedStatement
    {
        $text = (string) $content;

        $last4 = $this->matchFirst('/Account\s*Ending\s*(\d{4,5})/i', $text)
            ?? $this->matchFirst('/(?:Card|Account)\s*number[^\d]*(\d{4,5})(?!\d)/i', $text);
        if ($last4 !== null && strlen($last4) > 4) {
            // Amex cards are 15 digits; last-5 is sometimes shown. Keep 4.
            $last4 = substr($last4, -4);
        }

        [$start, $end] = $this->matchPeriod($text);
        $opening = $this->matchMoney('/Previous\s*Balance\s*\$?\s*([\-0-9,\.]+)/i', $text);
        $closing = $this->matchMoney('/New\s*Balance\s*\$?\s*([\-0-9,\.]+)/i', $text);

        return new ParsedStatement(
            bankSlug: 'amex_credit',
            bankLabel: 'Amex — Credit Card',
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
        $paymentsContext = false;
        $lines = preg_split('/\r?\n/', $text) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/Payments\s*and\s*Credits|Payments,\s*Credits|Credits/i', $line)) {
                $paymentsContext = true;

                continue;
            }
            if (preg_match('/New\s*Charges|Charges|Fees|Interest\s*Charged/i', $line)) {
                $paymentsContext = false;

                continue;
            }
            // "MM/DD/YY* description amount" (star = foreign txn sometimes)
            if (preg_match('/^\s*(\d{1,2}\/\d{1,2}\/\d{2})\*?\s+(.+?)\s{2,}\$?\s*([\-0-9,\.]+)\s*$/', $line, $m)) {
                $date = self::date($m[1]);
                $amount = self::money($m[3]);
                if ($date === null || $amount === null) {
                    continue;
                }
                // Amex credit: charges positive on doc, stored negative.
                // Payments/credits positive in Bureau.
                $signed = $paymentsContext ? abs($amount) : -abs($amount);
                $rows[] = new ParsedTransaction(
                    occurredOn: $date,
                    description: trim($m[2]),
                    amount: $signed,
                    rawRow: trim($line),
                );
            } elseif (preg_match('/^\s*(\d{1,2}\/\d{1,2})\s+(.+?)\s{2,}\$?\s*([\-0-9,\.]+)\s*$/', $line, $m)) {
                // Older format without year
                $date = $this->parseMonthDay($m[1], $assumeYear);
                $amount = self::money($m[3]);
                if ($date === null || $amount === null) {
                    continue;
                }
                $signed = $paymentsContext ? abs($amount) : -abs($amount);
                $rows[] = new ParsedTransaction(
                    occurredOn: $date,
                    description: trim($m[2]),
                    amount: $signed,
                    rawRow: trim($line),
                );
            }
        }

        return $rows;
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private function matchPeriod(string $text): array
    {
        if (preg_match('/Closing\s*Date[^\n]*?(\d{1,2}\/\d{1,2}\/\d{2,4})/i', $text, $m)) {
            $end = self::date($m[1]);
            $start = $end?->subMonth()->addDay();

            return [$start, $end];
        }
        if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{2,4})\s*(?:-|to|through|–|—)\s*(\d{1,2}\/\d{1,2}\/\d{2,4})/i', $text, $m)) {
            return [self::date($m[1]), self::date($m[2])];
        }

        return [null, null];
    }
}

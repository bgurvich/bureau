<?php

namespace App\Support\Statements\Parsers\Pdf;

use App\Support\Statements\ParsedStatement;
use App\Support\Statements\ParsedTransaction;
use App\Support\Statements\StatementParser;
use Carbon\CarbonImmutable;

/**
 * OnPoint Community Credit Union credit card PDF.
 *
 * Layout cues: "OnPoint" + "Visa" or "Mastercard" or "Credit Card
 * Statement". Transaction rows: "MM/DD  MM/DD  description  amount" with
 * optional "CR" suffix on credits. Charges positive on source; Secretaire
 * stores credit-card charges as negative.
 */
final class OnPointCreditStatementParser implements StatementParser
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
            && preg_match('/Visa|Mastercard|Credit\s*Card\s*Statement/i', $content);
    }

    public function parse(string|array $content): ParsedStatement
    {
        $text = (string) $content;

        $last4 = $this->matchFirst('/Account\s*ending\s*in\s*(\d{4})/i', $text)
            ?? $this->matchFirst('/(?:Card|Account)\s*number[^\d]*(\d{4})(?!\d)/i', $text);
        [$start, $end] = $this->matchPeriod($text);
        $opening = $this->matchMoney('/Previous\s*Balance\s*\$?\s*([\-0-9,\.]+)/i', $text);
        $closing = $this->matchMoney('/New\s*Balance\s*\$?\s*([\-0-9,\.]+)/i', $text);

        return new ParsedStatement(
            bankSlug: 'onpoint_credit',
            bankLabel: 'OnPoint CU — Credit Card',
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
            if (preg_match('/^\s*(\d{1,2}\/\d{1,2})\s+(?:\d{1,2}\/\d{1,2}\s+)?(.+?)\s{2,}([\-0-9,\.]+)\s*(CR)?\s*$/', $line, $m)) {
                $date = $this->parseMonthDay($m[1], $assumeYear);
                $amount = self::money($m[3]);
                if ($date === null || $amount === null) {
                    continue;
                }
                $isCredit = ! empty($m[4]) || $amount < 0;
                $signed = $isCredit ? abs($amount) : -abs($amount);
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
        if (preg_match('/(?:Statement|Billing)\s*Period[^\n]*?(\d{1,2}\/\d{1,2}\/\d{2,4})\s*(?:-|to|through|–|—)\s*(\d{1,2}\/\d{1,2}\/\d{2,4})/is', $text, $m)) {
            return [self::date($m[1]), self::date($m[2])];
        }
        if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{2,4})\s*(?:-|to|through|–|—)\s*(\d{1,2}\/\d{1,2}\/\d{2,4})/i', $text, $m)) {
            return [self::date($m[1]), self::date($m[2])];
        }

        return [null, null];
    }
}

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
        $lines = preg_split('/\r?\n/', $text) ?: [];

        // WF checking statements come in two layouts:
        //   (a) Sectioned — separate "Deposits and Other Additions" /
        //       "Withdrawals and Other Subtractions" lists with unsigned
        //       amounts. Section headers carry the sign.
        //   (b) Activity Summary — one table with three columns:
        //       Deposits/Additions | Withdrawals/Subtractions |
        //       Ending daily balance. pdftotext -layout preserves the
        //       horizontal positions; we prefer anchoring to the header
        //       line ("Deposits... Withdrawals... Ending...") because
        //       it's robust to sparse data, and fall back to clustering
        //       token end-offsets when the header isn't recognisable.
        //
        // We detect (b) first because it's unambiguous when it matches;
        // (a) is the fallback for simple layouts without aligned columns.
        $candidates = $this->gatherCandidateRows($lines);
        $bounds = $this->columnBoundsFromHeader($lines)
            ?? $this->columnBoundsFromClustering($candidates);

        return $bounds !== null
            ? $this->extractByColumns($candidates, $bounds, $assumeYear)
            : $this->extractBySections($lines, $assumeYear);
    }

    /**
     * Boundaries are exclusive upper limits: a token at position P is in
     * column i if $bounds[i-1] <= P < $bounds[i] (with $bounds[-1] = 0).
     *   bounds[0] = end of Deposits column
     *   bounds[1] = end of Withdrawals column
     *   bounds[2] = end of Balance column (effectively the line length)
     * Same shape from header-anchoring OR clustering so the classifier
     * below doesn't care how we got them.
     *
     * @param  array<int, string>  $lines
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function columnBoundsFromHeader(array $lines): ?array
    {
        $anchors = $this->detectColumnsFromHeader($lines);
        if ($anchors === null) {
            return null;
        }

        // Header gives column START positions — tokens in column i are
        // the money values that right-align somewhere between the
        // column's start and the NEXT column's start. So the upper
        // bound of each column is the start of the one after it.
        return [$anchors[1], $anchors[2], PHP_INT_MAX];
    }

    /**
     * @param  array<int, array{line: string, tokens: array<int, array{text: string, end: int}>, continuation: array<int, string>}>  $candidates
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function columnBoundsFromClustering(array $candidates): ?array
    {
        $centers = $this->detectThreeColumnLayout($candidates);
        if ($centers === null) {
            return null;
        }

        // Clustering gives column CENTER positions (end-of-token
        // averages). Boundaries go at the midpoints between adjacent
        // centers, and the Balance column extends to the right edge.
        return [
            (int) floor(($centers[0] + $centers[1]) / 2),
            (int) floor(($centers[1] + $centers[2]) / 2),
            PHP_INT_MAX,
        ];
    }

    /**
     * Anchor columns to the header line — "Deposits" / "Withdrawals" /
     * "Ending" or "balance", left-to-right. Returns three positions
     * (start-of-column markers) used as classification anchors. Null if
     * the statement doesn't look like an Activity Summary layout.
     *
     * @param  array<int, string>  $lines
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function detectColumnsFromHeader(array $lines): ?array
    {
        foreach ($lines as $line) {
            // Same line must contain Deposits + Withdrawals. Balance
            // header word is often on the same line ("Ending daily
            // balance") but sometimes wraps to a second line — we take
            // the first of "Ending" / "balance" we find past Withdrawals.
            $dep = stripos($line, 'Deposits');
            $wd = stripos($line, 'Withdrawals');
            if ($dep === false || $wd === false || $dep >= $wd) {
                continue;
            }
            $bal = false;
            foreach (['Ending', 'balance', 'Balance'] as $candidate) {
                $p = stripos($line, $candidate, $wd + strlen('Withdrawals'));
                if ($p !== false) {
                    $bal = $p;
                    break;
                }
            }
            if ($bal === false) {
                continue;
            }

            return [$dep, $wd, $bal];
        }

        return null;
    }

    /**
     * Pull every line that looks like a transaction row (date prefix +
     * at least one money-shaped token). WF checking sometimes wraps a
     * long description onto a SECOND line with no date and no money —
     * those lines belong to the previous transaction and are captured
     * in $continuation so extractByColumns can append them to the
     * description. An intermediate blank line, a header row, or a line
     * that starts with a date all close the continuation window for
     * the previous row.
     *
     * @param  array<int, string>  $lines
     * @return array<int, array{line: string, tokens: array<int, array{text: string, end: int}>, continuation: array<int, string>}>
     */
    private function gatherCandidateRows(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            // Transaction-start line — date prefix + at least one money
            // token right-aligned somewhere on the line.
            if (preg_match('/^\s*\d{1,2}\/\d{1,2}\s/', $line)
                && preg_match_all('/-?\$?[\d,]+\.\d{2}/', $line, $m, PREG_OFFSET_CAPTURE)
                && $m[0] !== []) {
                $tokens = [];
                foreach ($m[0] as $hit) {
                    $tokens[] = ['text' => $hit[0], 'end' => $hit[1] + strlen($hit[0])];
                }
                $out[] = ['line' => $line, 'tokens' => $tokens, 'continuation' => []];

                continue;
            }

            // Blank / whitespace-only — close any pending continuation.
            if (trim($line) === '') {
                continue;
            }
            // Header-ish lines (column titles, section dividers) are
            // anything that mentions "Deposits" or "Withdrawals" or
            // "Balance" without a money token — skip them.
            if (preg_match('/Deposits|Withdrawals|Ending|Balance/i', $line)
                && ! preg_match('/\d{1,2}\/\d{1,2}/', $line)) {
                continue;
            }
            // Line with no date and no money → continuation text for
            // the previous row if we have one. Cap at three extension
            // lines so a runaway footer block can't be swallowed.
            if (! empty($out) && ! preg_match('/^\s*\d{1,2}\/\d{1,2}\s/', $line)) {
                if (count($out[count($out) - 1]['continuation']) < 3) {
                    $out[count($out) - 1]['continuation'][] = trim($line);
                }
            }
        }

        return $out;
    }

    /**
     * Cluster the end-character offsets of every money token across the
     * candidate rows. If we find three well-separated clusters, return
     * them left-to-right as the deposit / withdrawal / balance columns;
     * otherwise return null so the caller falls back to the section-
     * based parser.
     *
     * @param  array<int, array{line: string, tokens: array<int, array{text: string, end: int}>}>  $candidates
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function detectThreeColumnLayout(array $candidates): ?array
    {
        $ends = [];
        foreach ($candidates as $row) {
            foreach ($row['tokens'] as $t) {
                $ends[] = $t['end'];
            }
        }
        if (count($ends) < 3) {
            return null;
        }
        sort($ends);

        // Group positions within ±1 character into one cluster — pdftotext
        // -layout right-aligns amounts to their column's right edge, so
        // real column positions coincide to the character. A looser
        // tolerance would merge neighbouring rows whose description
        // widths happen to shift the amount by a few characters.
        $clusters = [[$ends[0]]];
        for ($i = 1, $n = count($ends); $i < $n; $i++) {
            $prev = end($clusters);
            if ($ends[$i] - end($prev) <= 1) {
                $clusters[count($clusters) - 1][] = $ends[$i];
            } else {
                $clusters[] = [$ends[$i]];
            }
        }

        // Require at least three clusters AND the three largest must hold
        // >=2 positions each — a statement with only one deposit row and
        // one balance row would otherwise pass column-detection on what's
        // really a 2-column layout with an outlier token in the description.
        if (count($clusters) < 3) {
            return null;
        }
        usort($clusters, fn ($a, $b) => count($b) <=> count($a));
        $top = array_slice($clusters, 0, 3);
        foreach ($top as $cluster) {
            if (count($cluster) < 2) {
                return null;
            }
        }
        $centers = array_map(fn ($c) => (int) round(array_sum($c) / count($c)), $top);
        sort($centers);

        // Require meaningful horizontal separation so we don't mis-classify
        // a two-column layout (amount + balance) as three.
        if ($centers[1] - $centers[0] < 5 || $centers[2] - $centers[1] < 5) {
            return null;
        }

        /** @var array{0: int, 1: int, 2: int} $centers */
        return $centers;
    }

    /**
     * @param  array<int, array{line: string, tokens: array<int, array{text: string, end: int}>, continuation: array<int, string>}>  $candidates
     * @param  array{0: int, 1: int, 2: int}  $bounds
     * @return array<int, ParsedTransaction>
     */
    private function extractByColumns(array $candidates, array $bounds, int $assumeYear): array
    {
        [$depHigh, $wdHigh, $balHigh] = $bounds;
        $rows = [];
        foreach ($candidates as $cand) {
            $line = $cand['line'];
            if (! preg_match('/^\s*(\d{1,2}\/\d{1,2})\s+(.+?)\s{2,}/', $line, $m)) {
                continue;
            }
            $date = $this->parseMonthDay($m[1], $assumeYear);
            if ($date === null) {
                continue;
            }

            // Classify each token by which column range its end-offset
            // falls into: deposit column < depHigh, withdrawal column
            // [depHigh, wdHigh), balance column [wdHigh, balHigh). The
            // first money token that isn't in the balance column is
            // the transaction amount; the balance token (if any) feeds
            // the runningBalance field.
            $amountToken = null;
            $amountColumn = null;       // 'dep' | 'wd'
            $balanceToken = null;
            foreach ($cand['tokens'] as $tok) {
                $col = $tok['end'] < $depHigh ? 'dep'
                    : ($tok['end'] < $wdHigh ? 'wd' : 'bal');
                if ($col === 'bal') {
                    $balanceToken = $tok;

                    continue;
                }
                if ($amountToken === null) {
                    $amountToken = $tok;
                    $amountColumn = $col;
                }
            }
            if ($amountToken === null) {
                continue;
            }

            $amount = self::money($amountToken['text']);
            if ($amount === null) {
                continue;
            }
            if ($amountColumn === 'wd' && $amount > 0) {
                $amount = -$amount;
            }

            // Merge continuation lines into the description. Collapse
            // runs of whitespace so reconstructed strings read cleanly
            // even when pdftotext-layout padded each fragment with
            // columnar whitespace.
            $description = trim($m[2]);
            if (! empty($cand['continuation'])) {
                $description = trim($description.' '.implode(' ', $cand['continuation']));
                $description = (string) preg_replace('/\s+/', ' ', $description);
            }

            $rows[] = new ParsedTransaction(
                occurredOn: $date,
                description: $description,
                amount: $amount,
                runningBalance: $balanceToken !== null ? self::money($balanceToken['text']) : null,
                rawRow: trim($line),
            );
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, ParsedTransaction>
     */
    private function extractBySections(array $lines, int $assumeYear): array
    {
        $rows = [];
        $credit = false;
        foreach ($lines as $line) {
            if (preg_match('/Deposits\s*and\s*Other\s*Additions/i', $line)) {
                $credit = true;

                continue;
            }
            if (preg_match('/Withdrawals\s*and\s*Other\s*Subtractions|Checks\s*paid|ATM\s*and\s*Debit\s*Card/i', $line)) {
                $credit = false;

                continue;
            }
            $pattern = '/^\s*(\d{1,2}\/\d{1,2})\s+(.+?)\s{2,}(-?\$?[\d,]+\.\d{2})(?:\s+-?\$?[\d,]+\.\d{2})?\s*$/';
            if (preg_match($pattern, $line, $m)) {
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

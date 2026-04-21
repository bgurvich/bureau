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
        // WF checking Activity Summary tables can reflow per page — each
        // page header ("Deposits/Additions | Withdrawals/Subtractions |
        // Ending") re-anchors slightly different character columns when
        // pdftotext -layout preserves page-local widths. Anchoring to
        // page 1's header and reusing those positions on pages 2+ is
        // what flipped mid-statement withdrawals into deposits. Split
        // the text on form-feed (pdftotext's page separator) and
        // process each page independently; fall back to single-page
        // behaviour if the stream has no form-feeds (older pdftotext
        // output, or a single-page statement).
        $pages = preg_split("/\f/", $text) ?: [$text];
        $rowsAll = [];
        // Last-known page bounds so a page without a header (e.g. a
        // continuation page whose header got clipped) can borrow
        // columns from the previous page instead of falling back to
        // sectioned mode, which would ignore column positions.
        $lastBounds = null;
        $lastCheckBounds = null;
        foreach ($pages as $page) {
            $lines = preg_split('/\r?\n/', $page) ?: [];
            $candidates = $this->gatherCandidateRows($lines);
            $bounds = $this->columnBoundsFromHeader($lines)
                ?? $this->columnBoundsFromClustering($candidates)
                ?? $lastBounds;
            // Optional Check Number column — only WF layouts that print
            // one will have "Number" (or "Check") sitting between Date
            // and Description in the header.
            $checkBounds = $this->detectCheckColumnFromHeader($lines) ?? $lastCheckBounds;

            if ($bounds !== null) {
                $lastBounds = $bounds;
                $lastCheckBounds = $checkBounds;
                array_push($rowsAll, ...$this->extractByColumns($candidates, $bounds, $assumeYear, $checkBounds));
            } else {
                array_push($rowsAll, ...$this->extractBySections($lines, $assumeYear));
            }
        }

        return $rowsAll;
    }

    /**
     * Locate the Check Number column's character range on a page. WF
     * prints it as a stacked two-word header ("Check" over "Number")
     * between Date and Description on checking layouts that include
     * paper-check activity. Returns [start, end] where `end` = first
     * character of the Description column, so the check token lives in
     * that horizontal slice of each row.
     *
     * @param  array<int, string>  $lines
     * @return array{0: int, 1: int}|null
     */
    private function detectCheckColumnFromHeader(array $lines): ?array
    {
        // Description column start — anchor for the right edge of the
        // Check column. Same "Description" keyword we rely on for the
        // row regex.
        $descStart = null;
        foreach ($lines as $line) {
            $d = stripos($line, 'Description');
            if ($d === false) {
                continue;
            }
            // Guard: "Description" on its own appears only in the
            // Activity Summary header, which also carries Deposits on
            // the same line (or the next line — tolerate both).
            $dep = stripos($line, 'Deposits');
            if ($dep !== false && $dep > $d) {
                $descStart = $d;
                break;
            }
            // Header split across two lines — keep looking if the next
            // line has "Deposits".
            $descStart = $d;
        }
        if ($descStart === null) {
            return null;
        }

        // Find "Number" or "Check" between the Date column (~0-6) and
        // Description. "Number" wins when both are present — on
        // two-line headers it sits directly above the column.
        foreach ($lines as $line) {
            foreach (['Number', 'Check'] as $word) {
                $p = stripos($line, $word);
                if ($p !== false && $p > 6 && $p < $descStart) {
                    return [$p, $descStart];
                }
            }
        }

        return null;
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
        // Once a column-totals row or footer marker appears on a page,
        // every subsequent non-date line is boilerplate — "Totals
        // $X $Y", "The Ending Daily Balance …", "If you had
        // insufficient available funds …". Before this flag, those
        // lines slipped into the previous transaction's description as
        // continuations (the cap is 3 lines, so the whole disclaimer
        // paragraph could get swallowed).
        $terminated = false;
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
                // A new transaction row resets termination — handles
                // multi-section statements where "Totals" appears
                // between Deposits and Withdrawals sub-tables and
                // further real rows follow.
                $terminated = false;

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
            // End-of-transactions markers: the column-sum row
            // ("Totals $X $Y"). From here to the next dated row,
            // nothing belongs in a transaction description.
            if (preg_match('/^\s*Totals?\b/i', $line)) {
                $terminated = true;

                continue;
            }
            if ($terminated) {
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
     * @param  array{0: int, 1: int}|null  $checkBounds
     * @return array<int, ParsedTransaction>
     */
    private function extractByColumns(array $candidates, array $bounds, int $assumeYear, ?array $checkBounds = null): array
    {
        [$depHigh, $wdHigh, $balHigh] = $bounds;
        $rows = [];
        foreach ($candidates as $cand) {
            $line = $cand['line'];

            // If the Check Number column is present, carve out whatever
            // token lives in its horizontal slice BEFORE the description
            // regex runs — otherwise `.+?\s{2,}` grabs the check token
            // ("<", a numeric check number) as the whole description
            // and stops at the wide gap that separates it from the real
            // description. Blanking the slice lets the regex proceed as
            // if no check column existed.
            $checkNumber = null;
            if ($checkBounds !== null) {
                [$ckStart, $ckEnd] = $checkBounds;
                $width = max(0, $ckEnd - $ckStart);
                if (strlen($line) > $ckStart && $width > 0) {
                    $slice = substr($line, $ckStart, $width);
                    $trimmed = trim($slice);
                    if ($trimmed !== '') {
                        $checkNumber = $trimmed;
                    }
                    $line = substr_replace($line, str_repeat(' ', $width), $ckStart, $width);
                }
            }

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
                rawRow: trim($cand['line']),
                checkNumber: $checkNumber,
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

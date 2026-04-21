<?php

namespace App\Support\Statements\Parsers\Pdf;

use Carbon\CarbonImmutable;

/**
 * Shared text-mining helpers for bank statement PDF parsers. Each bank has
 * its own transaction-row regex, but the primitives — parse a date,
 * normalize a currency string, pluck a capture group, resolve a bare m/d
 * against the statement year — are identical across Amex / Citi /
 * Wells Fargo / OnPoint. This trait keeps them in one place so a bug fix
 * (say, a new date format) lands in every parser at once.
 */
trait PdfParserHelpers
{
    protected static function date(string $raw): ?CarbonImmutable
    {
        // Carbon::parse('') and ::parse(null) silently return TODAY — so a
        // parser that pipes an empty date column into this helper used to
        // write today's date into Transaction.occurred_on, making every
        // import land in the current month. Short-circuit on empty BEFORE
        // the fallback so we return null (→ row skipped) instead.
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        foreach (['m/d/Y', 'm/d/y', 'n/j/Y', 'n/j/y'] as $fmt) {
            try {
                $d = CarbonImmutable::createFromFormat($fmt, $raw);
                if ($d) {
                    return $d;
                }
            } catch (\Throwable) {
            }
        }
        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    protected static function money(string $raw): ?float
    {
        $clean = str_replace([',', '$', ' '], '', trim($raw));
        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }

        return (float) $clean;
    }

    protected function matchFirst(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $m) ? $m[1] : null;
    }

    protected function matchMoney(string $pattern, string $text): ?float
    {
        return preg_match($pattern, $text, $m) ? self::money($m[1]) : null;
    }

    protected function parseMonthDay(string $md, int $year): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('m/d/Y', $md.'/'.$year) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

namespace App\Support\Statements\Parsers\Csv;

use Carbon\CarbonImmutable;

trait CsvParserHelpers
{
    /**
     * Return the first value found across any of the header aliases,
     * case-insensitively. Trims the result; null if none match.
     *
     * @param  array<string, string>  $row
     * @param  array<int, string>  $aliases
     */
    protected function cell(array $row, array $aliases): ?string
    {
        foreach ($row as $key => $value) {
            $kl = strtolower(trim($key));
            foreach ($aliases as $alias) {
                if ($kl === strtolower($alias)) {
                    $v = trim((string) $value);

                    return $v === '' ? null : $v;
                }
            }
        }

        return null;
    }

    protected function money(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }
        $clean = str_replace([',', '$', ' '], '', trim($raw));
        $clean = trim($clean, '()');
        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }
        // Parenthesized values → negative (accounting convention seen in
        // some exports).
        if (preg_match('/^\(.*\)$/', trim($raw))) {
            return -abs((float) $clean);
        }

        return (float) $clean;
    }

    protected function date(?string $raw): ?CarbonImmutable
    {
        // Carbon::parse('') and ::parse(null) silently return TODAY — so a
        // parser that pipes an empty date cell into this helper used to
        // write today's date into Transaction.occurred_on, making every
        // import land in the current month. Short-circuit on empty BEFORE
        // the fallback so we return null (→ row skipped) instead.
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        foreach (['m/d/Y', 'm/d/y', 'n/j/Y', 'n/j/y', 'Y-m-d', 'm-d-Y'] as $fmt) {
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

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $required
     */
    protected function headersMatch(array $headers, array $required): bool
    {
        $lower = array_map(fn ($h) => strtolower(trim($h)), $headers);
        foreach ($required as $r) {
            if (! in_array(strtolower($r), $lower, true)) {
                return false;
            }
        }

        return true;
    }
}

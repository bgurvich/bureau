<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Household;

/**
 * Strip household-configured filler phrases from transaction descriptions
 * before vendor auto-detection or fingerprinting runs. Bank statements
 * prefix every row with the same boilerplate — "Purchase authorized on",
 * "POS purchase", "ACH transfer from" — which pollutes the first two
 * meaningful words used to match against existing Contacts and to seed
 * auto-created vendor names.
 *
 * Patterns are stored as newline-separated regex lines in
 * `households.data.vendor_ignore_patterns`. Each line is a regex body
 * (no delimiters, no flags) and is matched case-insensitively. Broken
 * regexes are skipped silently so one bad line doesn't abort parsing
 * for a whole statement batch.
 */
final class DescriptionNormalizer
{
    /**
     * Apply every configured ignore pattern to $raw. Returns the
     * description with matches replaced by a single space; the caller
     * is responsible for collapsing whitespace if needed (fingerprint
     * and humanize already do that).
     */
    public static function stripIgnoredPatterns(string $raw, ?Household $household = null): string
    {
        $patterns = self::patternsFor($household);
        if ($patterns === []) {
            return $raw;
        }
        foreach ($patterns as $pattern) {
            // '#' delimiter + 'i' flag + 'u' for unicode safety. Skip a
            // pattern that preg_replace rejects (bad backreference,
            // unbalanced group, etc.) rather than let one typo poison
            // the whole import.
            $result = @preg_replace('#'.str_replace('#', '\#', $pattern).'#iu', ' ', $raw);
            if (is_string($result)) {
                $raw = $result;
            }
        }

        return $raw;
    }

    /**
     * @return array<int, string>
     */
    public static function patternsFor(?Household $household = null): array
    {
        $household ??= CurrentHousehold::get();
        if (! $household) {
            return [];
        }
        $raw = data_get($household->data, 'vendor_ignore_patterns');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out;
    }
}

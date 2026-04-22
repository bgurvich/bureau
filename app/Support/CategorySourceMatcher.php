<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;

/**
 * Match a source-supplied category label (e.g. the "Category" column on a
 * Costco Anywhere Visa statement) to a household Category via the
 * patterns stored in `categories.match_patterns`. Parallel in shape to
 * VendorReresolver's pattern matching for contacts — one regex or plain
 * substring per line, matched case-insensitively, first hit wins.
 *
 * Kept distinct from `category_rules` (which matches transaction
 * descriptions): source-label matching uses the bank/issuer's taxonomy
 * as the input, not the transaction text. Both signals layer together
 * during import — CategoryRule fires first, this fills in the blanks.
 */
final class CategorySourceMatcher
{
    /**
     * Parse the textarea into a flat pattern list, empty lines skipped.
     * Preserves order so earlier lines win at match time.
     *
     * @return array<int, string>
     */
    public static function parsePatterns(string $raw): array
    {
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim !== '') {
                $out[] = $trim;
            }
        }

        return $out;
    }

    /**
     * Flat (category_id, pattern) list for the current household, walked
     * in patternList order until one hits. Categories with no patterns
     * are skipped — unlike contacts we never self-seed from the name
     * because a category name like "Merchandise" is too generic to
     * blindly use as a pattern.
     *
     * @return array<int, array{0: int, 1: string}>
     */
    public static function patternList(): array
    {
        $out = [];
        foreach (Category::query()->select(['id', 'match_patterns'])->cursor() as $c) {
            $raw = is_string($c->match_patterns) ? $c->match_patterns : '';
            if (trim($raw) === '') {
                continue;
            }
            foreach (self::parsePatterns($raw) as $p) {
                $out[] = [(int) $c->id, $p];
            }
        }

        return $out;
    }

    /**
     * Return the first category_id whose patterns match $sourceLabel,
     * or null if nothing matched. Matching is case-insensitive via
     * the 'i' flag so plain-substring patterns ("merchandise") work
     * the same as anchored regex ("^Health Care$").
     *
     * @param  array<int, array{0: int, 1: string}>|null  $pairs  optional pre-built list; lets callers batch a single DB read across many rows
     */
    public static function match(string $sourceLabel, ?array $pairs = null): ?int
    {
        $hit = self::matchWithPattern($sourceLabel, $pairs);

        return $hit === null ? null : $hit[0];
    }

    /**
     * Like match() but returns the (category_id, pattern) pair so
     * callers can surface *which* pattern produced the match in the
     * import preview.
     *
     * @param  array<int, array{0: int, 1: string}>|null  $pairs
     * @return array{0: int, 1: string}|null
     */
    public static function matchWithPattern(string $sourceLabel, ?array $pairs = null): ?array
    {
        $label = trim($sourceLabel);
        if ($label === '') {
            return null;
        }

        $pairs ??= self::patternList();
        $haystack = mb_strtolower($label);

        foreach ($pairs as [$id, $pattern]) {
            $regex = '#'.str_replace('#', '\#', $pattern).'#iu';
            if (@preg_match($regex, $haystack) === 1) {
                return [$id, $pattern];
            }
        }

        return null;
    }
}

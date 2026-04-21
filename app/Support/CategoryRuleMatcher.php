<?php

namespace App\Support;

use App\Models\CategoryRule;
use App\Models\Transaction;

/**
 * Applies user-defined description patterns to auto-categorize transactions.
 * Rules are ordered by priority ASC (smaller wins); first match sets the
 * category. Runs only on transactions with `category_id IS NULL` — never
 * overrides a manually-chosen category.
 *
 * Pattern types:
 *   - `contains` — case-insensitive substring match
 *   - `regex`    — PHP regex WITHOUT delimiters or flags. The matcher adds
 *                  `/.../i` itself. Invalid regexes are quietly ignored so
 *                  one bad row can't stop the whole sweep.
 */
class CategoryRuleMatcher
{
    public static function attempt(Transaction $transaction): ?CategoryRule
    {
        if ($transaction->category_id) {
            return null;
        }
        $desc = (string) ($transaction->description ?? '');
        if ($desc === '') {
            return null;
        }

        $rules = CategoryRule::where('active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get(['id', 'pattern_type', 'pattern', 'category_id']);

        foreach ($rules as $rule) {
            if (self::matches($rule, $desc)) {
                $transaction->forceFill(['category_id' => $rule->category_id])->save();

                return $rule;
            }
        }

        return null;
    }

    private static function matches(CategoryRule $rule, string $haystack): bool
    {
        $pattern = (string) $rule->pattern;
        if ($pattern === '') {
            return false;
        }

        if ($rule->pattern_type === 'regex') {
            // Guard against an invalid user-supplied regex poisoning the sweep.
            $delimited = '/'.str_replace('/', '\\/', $pattern).'/i';

            return @preg_match($delimited, $haystack) === 1;
        }

        return mb_stripos($haystack, $pattern) !== false;
    }
}

<?php

namespace App\Support;

use App\Models\TagRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies user-defined description patterns to auto-tag transactions (or
 * any taggable model that carries a stringy "description" field). Mirrors
 * CategoryRuleMatcher but attaches tags instead of setting a category, and
 * is additive — a record can collect tags from every matching rule, not
 * just the highest-priority one.
 */
class TagRuleMatcher
{
    /**
     * Apply active tag rules to a model's description. Returns the number
     * of tags newly attached (existing attachments are untouched — this is
     * a syncWithoutDetaching call under the hood).
     */
    /**
     * Public helper for live-preview in the admin UI. Uses the same pattern
     * normalization as attempt(). Broken regexes silently return false.
     */
    public static function patternMatches(string $patternType, string $pattern, string $haystack): bool
    {
        if ($pattern === '' || $haystack === '') {
            return false;
        }
        if ($patternType === 'regex') {
            return @preg_match('/'.str_replace('/', '\\/', $pattern).'/i', $haystack) === 1;
        }

        return mb_stripos($haystack, $pattern) !== false;
    }

    public static function attempt(Model $model, string $descriptionField = 'description'): int
    {
        /** @var string $desc */
        $desc = (string) ($model->{$descriptionField} ?? '');
        if ($desc === '') {
            return 0;
        }

        $rules = TagRule::where('active', true)->get(['id', 'tag_id', 'pattern_type', 'pattern']);
        if ($rules->isEmpty()) {
            return 0;
        }

        $tagIds = [];
        foreach ($rules as $rule) {
            if (self::matches($rule, $desc)) {
                $tagIds[] = $rule->tag_id;
            }
        }

        if ($tagIds === []) {
            return 0;
        }

        /** @phpstan-ignore-next-line — `tags()` comes from the HasTags trait */
        $changes = $model->tags()->syncWithoutDetaching(array_unique($tagIds));

        return count($changes['attached'] ?? []);
    }

    private static function matches(TagRule $rule, string $haystack): bool
    {
        $pattern = (string) $rule->pattern;
        if ($pattern === '') {
            return false;
        }
        if ($rule->pattern_type === 'regex') {
            return @preg_match('/'.str_replace('/', '\\/', $pattern).'/i', $haystack) === 1;
        }

        return mb_stripos($haystack, $pattern) !== false;
    }
}

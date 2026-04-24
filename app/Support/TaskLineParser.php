<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Parses a single-line task draft for bulk entry.
 *
 * Input form: `<title text> [#tag]* [@contact_pattern]* [P1-P5]? [<date-token>]?`
 * where `<date-token>` is one of:
 *   - `by M/D` or `by MM/DD`  (explicit month/day; year rolls forward)
 *   - `today`, `tomorrow`, `yesterday`
 *   - `this weekend` (→ Saturday)
 *   - `next week` (→ upcoming Monday)
 *   - `in N days`, `in N weeks`
 *   - a weekday name (`monday`..`sunday`; means the next such day)
 *
 * Tokens can appear in any order. **Tags + contacts stay visible in
 * the title** because stripping them leaves awkward gaps ("call @alice
 * about taxes" → "call about taxes"). Priority + date tokens are
 * stripped — they're pure metadata the title doesn't need to carry.
 *
 * Ambiguous or malformed fragments are left in the title rather than
 * dropped, so the user sees them and can correct. No contact lookups,
 * no tag creation — the parser is a pure function; persistence is the
 * caller's job.
 */
final class TaskLineParser
{
    /**
     * @return array{title: string, due_at: ?string, priority: ?int, tags: array<int, string>, contact_patterns: array<int, string>}|null
     */
    public static function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        // Record tag + contact matches *without* removing them from the
        // line — stripping "@alice" leaves "call about taxes" which
        // reads broken. The title keeps its shape; the chip list +
        // subject link capture the structured data.
        $tags = [];
        if (preg_match_all('/(?<![A-Za-z0-9_\-])#([A-Za-z0-9_\-]+)/', $line, $matches)) {
            $tags = $matches[1];
        }
        $contacts = [];
        if (preg_match_all('/(?<![A-Za-z0-9._\-])@([A-Za-z0-9._\-]+)/', $line, $matches)) {
            $contacts = $matches[1];
        }

        // P1..P5 priority — accept uppercase at a word boundary. Only
        // the first match wins so "P1 and P3" doesn't ping-pong.
        $priority = null;
        $line = preg_replace_callback('/\bP([1-5])\b/', function ($m) use (&$priority) {
            if ($priority !== null) {
                return $m[0];
            }
            $priority = (int) $m[1];

            return ' ';
        }, $line) ?? $line;

        $dueAt = null;

        // Explicit "by M/D" — deterministic, lowest surprise.
        $line = preg_replace_callback('/\bby\s+(\d{1,2})\/(\d{1,2})\b/i', function ($m) use (&$dueAt) {
            if ($dueAt !== null) {
                return $m[0];
            }
            $month = (int) $m[1];
            $day = (int) $m[2];
            $year = (int) now()->year;
            if (! checkdate($month, $day, $year)) {
                return $m[0];
            }
            $candidate = CarbonImmutable::create($year, $month, $day);
            $today = CarbonImmutable::today();
            if ($candidate->lt($today)) {
                $candidate = $candidate->addYear();
            }
            $dueAt = $candidate->setTime(9, 0, 0);

            return ' ';
        }, $line) ?? $line;

        // Natural-language dates. Long patterns first so "tomorrow"
        // matches before a bare "morrow" partial ever could.
        $nlPatterns = [
            '/\bin\s+(\d+)\s+weeks?\b/i' => fn ($m) => CarbonImmutable::today()->addWeeks((int) $m[1]),
            '/\bin\s+(\d+)\s+days?\b/i' => fn ($m) => CarbonImmutable::today()->addDays((int) $m[1]),
            '/\bnext\s+week\b/i' => fn () => CarbonImmutable::today()->addWeek()->startOfWeek(),
            '/\bthis\s+weekend\b/i' => fn () => CarbonImmutable::today()->next(CarbonImmutable::SATURDAY),
            '/\btomorrow\b/i' => fn () => CarbonImmutable::today()->addDay(),
            '/\byesterday\b/i' => fn () => CarbonImmutable::today()->subDay(),
            '/\btoday\b/i' => fn () => CarbonImmutable::today(),
            '/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i' => function ($m) {
                // Bare weekday name = the next occurrence (strictly
                // after today) — typing "Saturday" on Saturday means
                // next Saturday, not today.
                $target = constant(CarbonImmutable::class.'::'.strtoupper($m[1]));

                return CarbonImmutable::today()->next($target);
            },
        ];

        foreach ($nlPatterns as $pattern => $resolver) {
            if ($dueAt !== null) {
                break;
            }
            $line = preg_replace_callback($pattern, function ($m) use ($resolver, &$dueAt) {
                if ($dueAt !== null) {
                    return $m[0];
                }
                $dueAt = $resolver($m)->setTime(9, 0, 0);

                return ' ';
            }, $line) ?? $line;
        }

        $title = trim((string) preg_replace('/\s+/', ' ', $line));
        if ($title === '') {
            return null;
        }

        return [
            'title' => $title,
            'due_at' => $dueAt?->toDateTimeString(),
            'priority' => $priority,
            'tags' => array_values(array_unique($tags)),
            'contact_patterns' => array_values(array_unique($contacts)),
        ];
    }

    /**
     * @return array<int, array{title: string, due_at: ?string, priority: ?int, tags: array<int, string>, contact_patterns: array<int, string>}>
     */
    public static function parseBlock(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $parsed = self::parseLine($line);
            if ($parsed !== null) {
                $out[] = $parsed;
            }
        }

        return $out;
    }
}

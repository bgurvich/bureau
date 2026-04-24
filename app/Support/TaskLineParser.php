<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Parses a single-line task draft for bulk entry.
 *
 * Input form: `<title text> [#tag]* [@contact_pattern]* [by M/D]?`
 * — tokens can appear in any order; the title is whatever remains after
 * the recognized fragments are plucked out.
 *
 * `by M/D` (or `by MM/DD`) coerces to the current year; if that date is
 * already in the past (strictly before today) it rolls to next year so
 * "by 1/5" typed in December means January 5 next year, not last January.
 * The `by` prefix is required so bare numeric fragments (phone numbers,
 * jersey numbers, etc.) don't get absorbed into due_at.
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

        $tags = [];
        $line = preg_replace_callback('/(?<![A-Za-z0-9_\-])#([A-Za-z0-9_\-]+)/', function ($m) use (&$tags) {
            $tags[] = $m[1];

            return ' ';
        }, $line) ?? $line;

        $contacts = [];
        $line = preg_replace_callback('/(?<![A-Za-z0-9._\-])@([A-Za-z0-9._\-]+)/', function ($m) use (&$contacts) {
            $contacts[] = $m[1];

            return ' ';
        }, $line) ?? $line;

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

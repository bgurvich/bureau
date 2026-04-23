<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Minimal RFC-5545 RRULE expander. Supports the subset Secretaire uses:
 *   FREQ=DAILY|WEEKLY|MONTHLY|YEARLY
 *   INTERVAL
 *   BYMONTHDAY (comma list)
 *   BYDAY      (comma list of weekday codes: MO,TU,WE,TH,FR,SA,SU)
 *   COUNT
 *   UNTIL
 *
 * Unsupported RRULE parts (BYSETPOS, BYWEEKNO, BYYEARDAY, prefix-notated
 * BYDAY like "2MO", BYHOUR/BYMINUTE/BYSECOND) are ignored — extend when a
 * concrete recurring rule needs them.
 */
class Rrule
{
    private const WEEKDAY_OFFSETS = [
        'MO' => 0, 'TU' => 1, 'WE' => 2, 'TH' => 3, 'FR' => 4, 'SA' => 5, 'SU' => 6,
    ];

    private const ITERATION_CAP = 5000;

    /**
     * Expand RRULE into concrete dates between DTSTART and HORIZON (inclusive).
     *
     * @return array<int, CarbonImmutable>
     */
    public static function expand(
        CarbonInterface|DateTimeInterface|string $dtstart,
        string $rrule,
        CarbonInterface|DateTimeInterface|string $horizon,
        CarbonInterface|DateTimeInterface|string|null $until = null,
    ): array {
        $parts = self::parse($rrule);
        $freq = $parts['FREQ'] ?? null;

        if (! in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            return [];
        }

        $interval = max(1, (int) ($parts['INTERVAL'] ?? 1));
        $count = isset($parts['COUNT']) ? max(0, (int) $parts['COUNT']) : null;
        $byMonthday = isset($parts['BYMONTHDAY'])
            ? array_values(array_filter(array_map('intval', explode(',', $parts['BYMONTHDAY']))))
            : null;
        $byDay = isset($parts['BYDAY'])
            ? array_values(array_filter(
                array_map(fn (string $c) => strtoupper(trim($c)), explode(',', $parts['BYDAY'])),
                fn (string $c) => isset(self::WEEKDAY_OFFSETS[$c]),
            ))
            : null;

        $start = CarbonImmutable::parse($dtstart)->startOfDay();
        $horizonDate = CarbonImmutable::parse($horizon)->startOfDay();
        $effectiveUntil = $horizonDate;
        if (isset($parts['UNTIL'])) {
            $rruleUntil = self::parseUntil($parts['UNTIL']);
            if ($rruleUntil && $rruleUntil->lt($effectiveUntil)) {
                $effectiveUntil = $rruleUntil;
            }
        }
        if ($until) {
            $extraUntil = CarbonImmutable::parse($until)->startOfDay();
            if ($extraUntil->lt($effectiveUntil)) {
                $effectiveUntil = $extraUntil;
            }
        }

        if ($effectiveUntil->lt($start)) {
            return [];
        }

        $dates = [];
        $seen = [];
        $emitted = 0;
        $cursor = $start;
        $iterations = 0;

        // Terminate when the cursor's *period* is past the horizon, not when
        // the cursor's raw date is. For WEEKLY with a mid-week DTSTART, the
        // cursor lands inside the next period after one hop (e.g. Wed → next
        // Wed), which skipped the containing week whenever the horizon fell
        // on that week's Mon/Tue. Same fix covers MONTHLY BYMONTHDAY=1 when
        // horizon lands on the 1st.
        $periodStart = static function (CarbonImmutable $c) use ($freq): CarbonImmutable {
            return match ($freq) {
                'DAILY' => $c,
                'WEEKLY' => $c->startOfWeek(CarbonInterface::MONDAY),
                'MONTHLY' => $c->startOfMonth(),
                'YEARLY' => $c->startOfYear(),
            };
        };

        while ($periodStart($cursor)->lte($effectiveUntil) && $iterations < self::ITERATION_CAP) {
            $iterations++;

            foreach (self::expandPeriod($cursor, $freq, $byMonthday, $byDay) as $candidate) {
                if ($candidate->lt($start) || $candidate->gt($effectiveUntil)) {
                    continue;
                }
                $key = $candidate->toDateString();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $dates[] = $candidate;
                $emitted++;
                if ($count !== null && $emitted >= $count) {
                    break 2;
                }
            }

            $cursor = match ($freq) {
                'DAILY' => $cursor->addDays($interval),
                'WEEKLY' => $cursor->addWeeks($interval),
                'MONTHLY' => $cursor->addMonthsNoOverflow($interval),
                'YEARLY' => $cursor->addYears($interval),
            };
        }

        usort($dates, fn (CarbonImmutable $a, CarbonImmutable $b) => $a <=> $b);

        return $dates;
    }

    /**
     * @param  array<int, int>|null  $byMonthday
     * @param  array<int, string>|null  $byDay
     * @return array<int, CarbonImmutable>
     */
    private static function expandPeriod(
        CarbonImmutable $cursor,
        string $freq,
        ?array $byMonthday,
        ?array $byDay,
    ): array {
        switch ($freq) {
            case 'DAILY':
                return [$cursor];

            case 'WEEKLY':
                if ($byDay) {
                    $weekStart = $cursor->startOfWeek(CarbonInterface::MONDAY);

                    return array_map(
                        fn (string $code) => $weekStart->addDays(self::WEEKDAY_OFFSETS[$code]),
                        $byDay,
                    );
                }

                return [$cursor];

            case 'MONTHLY':
                if ($byMonthday) {
                    $year = (int) $cursor->format('Y');
                    $month = (int) $cursor->format('m');
                    $lastDay = (int) $cursor->endOfMonth()->format('d');

                    $out = [];
                    foreach ($byMonthday as $day) {
                        if ($day >= 1 && $day <= $lastDay) {
                            $out[] = CarbonImmutable::createFromDate($year, $month, $day)->startOfDay();
                        }
                    }

                    return $out;
                }

                return [$cursor];

            case 'YEARLY':
                return [$cursor];
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private static function parse(string $rrule): array
    {
        $parts = [];
        foreach (explode(';', $rrule) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $segment, 2), 2, '');
            $parts[strtoupper(trim($k))] = trim($v);
        }

        return $parts;
    }

    private static function parseUntil(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Accept: YYYYMMDD, YYYYMMDDTHHMMSS, YYYYMMDDTHHMMSSZ, or ISO-8601.
        $formats = ['Ymd', 'Ymd\THis', 'Ymd\THis\Z'];
        foreach ($formats as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value, 'UTC');
                if ($parsed) {
                    return $parsed->startOfDay();
                }
            } catch (\Throwable) {
                // try the next format
            }
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}

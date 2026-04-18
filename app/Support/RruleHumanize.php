<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Renders an RFC-5545 RRULE into a short human-readable phrase for
 * dashboards and rule catalogs. Covers the subset Bureau emits:
 * FREQ + INTERVAL + BYMONTHDAY + BYDAY + COUNT=1 (one-off).
 *
 * Unsupported parts are ignored and we fall back to "Repeats <FREQ>".
 */
class RruleHumanize
{
    /** @var array<string, string> */
    private const WEEKDAYS = [
        'MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday',
        'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday', 'SU' => 'Sunday',
    ];

    public static function describe(string $rrule, null|CarbonInterface|DateTimeInterface|string $dtstart = null): string
    {
        $parts = self::parse($rrule);
        $freq = strtoupper($parts['FREQ'] ?? '');
        $interval = max(1, (int) ($parts['INTERVAL'] ?? 1));
        $count = isset($parts['COUNT']) ? (int) $parts['COUNT'] : null;

        // One-off: COUNT=1 on a daily / single-occurrence rule
        if ($count === 1) {
            return __('Once on :date', ['date' => self::formatDate($dtstart)]);
        }

        $every = $interval === 1 ? '' : __(' every :n', ['n' => $interval]);

        $result = match ($freq) {
            'DAILY' => $interval === 1 ? __('Every day') : __('Every :n days', ['n' => $interval]),
            'WEEKLY' => self::weekly($parts, $interval),
            'MONTHLY' => self::monthly($parts, $interval),
            'YEARLY' => $interval === 1 ? __('Yearly') : __('Every :n years', ['n' => $interval]),
            default => __('Repeats'),
        };

        if ($count !== null && $count > 1) {
            $result .= ' · '.__(':n times', ['n' => $count]);
        }

        if (isset($parts['UNTIL'])) {
            $result .= ' · '.__('until :date', ['date' => self::formatDate($parts['UNTIL'])]);
        }

        return $result;
    }

    /** @param  array<string, string>  $parts */
    private static function weekly(array $parts, int $interval): string
    {
        if (isset($parts['BYDAY'])) {
            $days = array_filter(array_map(
                fn (string $c) => self::WEEKDAYS[strtoupper(trim($c))] ?? null,
                explode(',', $parts['BYDAY']),
            ));
            if ($days) {
                $joined = implode(', ', $days);

                return $interval === 1
                    ? __('Weekly on :days', ['days' => $joined])
                    : __('Every :n weeks on :days', ['n' => $interval, 'days' => $joined]);
            }
        }

        return $interval === 1 ? __('Weekly') : __('Every :n weeks', ['n' => $interval]);
    }

    /** @param  array<string, string>  $parts */
    private static function monthly(array $parts, int $interval): string
    {
        if (isset($parts['BYMONTHDAY'])) {
            $days = array_filter(array_map('intval', explode(',', $parts['BYMONTHDAY'])));
            if ($days) {
                $ordinals = array_map(fn ($d) => self::ordinal($d), $days);
                $joined = implode(', ', $ordinals);

                return $interval === 1
                    ? __('Monthly on the :days', ['days' => $joined])
                    : __('Every :n months on the :days', ['n' => $interval, 'days' => $joined]);
            }
        }

        return $interval === 1 ? __('Monthly') : __('Every :n months', ['n' => $interval]);
    }

    private static function ordinal(int $day): string
    {
        $s = ['th', 'st', 'nd', 'rd'];
        $v = $day % 100;
        $suffix = $s[($v - 20) % 10] ?? $s[$v] ?? $s[0];

        return $day.$suffix;
    }

    /** @return array<string, string> */
    private static function parse(string $rrule): array
    {
        $out = [];
        foreach (explode(';', $rrule) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $piece, 2), 2, '');
            $out[strtoupper(trim($k))] = trim($v);
        }

        return $out;
    }

    private static function formatDate(null|CarbonInterface|DateTimeInterface|string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return Formatting::date($value instanceof DateTimeInterface ? $value : (string) $value);
    }
}

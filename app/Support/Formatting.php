<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class Formatting
{
    public static function date(null|string|CarbonInterface $value): string
    {
        if ($value === null) {
            return '';
        }
        $dt = $value instanceof CarbonInterface ? $value : Carbon::parse($value);
        $user = auth()->user();

        return $dt->copy()
            ->setTimezone($user?->timezone ?? config('app.timezone', 'UTC'))
            ->format($user?->date_format ?? 'Y-m-d');
    }

    public static function time(null|string|CarbonInterface $value): string
    {
        if ($value === null) {
            return '';
        }
        $dt = $value instanceof CarbonInterface ? $value : Carbon::parse($value);
        $user = auth()->user();

        return $dt->copy()
            ->setTimezone($user?->timezone ?? config('app.timezone', 'UTC'))
            ->format($user?->time_format ?? 'H:i');
    }

    public static function datetime(null|string|CarbonInterface $value): string
    {
        if ($value === null) {
            return '';
        }

        return self::date($value).' '.self::time($value);
    }

    public static function money(float|int $amount, string $currency = 'USD'): string
    {
        return $currency.' '.number_format((float) $amount, 2);
    }
}

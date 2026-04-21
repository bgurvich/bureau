<?php

namespace App\Support;

use App\Models\User;
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
        $tz = $user instanceof User ? $user->timezone : null;
        $fmt = $user instanceof User ? $user->date_format : null;

        return $dt->copy()
            ->setTimezone($tz ?? config('app.timezone', 'UTC'))
            ->format($fmt ?? 'Y-m-d');
    }

    public static function time(null|string|CarbonInterface $value): string
    {
        if ($value === null) {
            return '';
        }
        $dt = $value instanceof CarbonInterface ? $value : Carbon::parse($value);
        $user = auth()->user();
        $tz = $user instanceof User ? $user->timezone : null;
        $fmt = $user instanceof User ? $user->time_format : null;

        return $dt->copy()
            ->setTimezone($tz ?? config('app.timezone', 'UTC'))
            ->format($fmt ?? 'H:i');
    }

    public static function datetime(null|string|CarbonInterface $value): string
    {
        if ($value === null) {
            return '';
        }

        return self::date($value).' '.self::time($value);
    }

    /**
     * ISO-4217 → symbol map. Only currencies Bureau users are plausibly going
     * to see; for anything missing we fall back to the raw code so we never
     * silently render the wrong glyph. Keep this list short and precise —
     * ambiguous symbols (e.g. "$" for USD vs MXN vs CAD) are disambiguated
     * with a prefix ("CA$", "MX$") when they might appear in the same view.
     */
    private const CURRENCY_SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
        'CHF' => 'CHF',
        'CAD' => 'CA$',
        'AUD' => 'A$',
        'NZD' => 'NZ$',
        'CNY' => 'CN¥',
        'HKD' => 'HK$',
        'SGD' => 'S$',
        'SEK' => 'kr',
        'NOK' => 'kr',
        'DKK' => 'kr',
        'PLN' => 'zł',
        'CZK' => 'Kč',
        'HUF' => 'Ft',
        'INR' => '₹',
        'KRW' => '₩',
        'THB' => '฿',
        'ILS' => '₪',
        'TRY' => '₺',
        'RUB' => '₽',
        'UAH' => '₴',
        'BRL' => 'R$',
        'MXN' => 'MX$',
        'ARS' => 'AR$',
        'ZAR' => 'R',
        'AED' => 'AED',
        'SAR' => 'SAR',
        'PHP' => '₱',
        'IDR' => 'Rp',
        'VND' => '₫',
    ];

    /**
     * Currencies where the symbol conventionally sits AFTER the number (the
     * Scandinavian kronor family). Everything else is prefix-positioned.
     */
    private const SUFFIX_CURRENCIES = ['SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF'];

    /**
     * Resolve an ISO-4217 code to its display glyph. Unknown codes round-trip
     * (returned as-is) so new currencies degrade to the raw code instead of
     * a silent wrong symbol.
     */
    public static function currencySymbol(?string $currency): string
    {
        if ($currency === null || $currency === '') {
            return '';
        }
        $code = strtoupper($currency);

        return self::CURRENCY_SYMBOLS[$code] ?? $code;
    }

    /**
     * Format a monetary amount with the correct symbol and placement. Negative
     * amounts keep the sign before the symbol ("-$42.00" rather than "$-42.00")
     * which matches how browsers and native number formatters render natively.
     */
    public static function money(float|int $amount, string $currency = 'USD'): string
    {
        $code = strtoupper($currency);
        $symbol = self::currencySymbol($code);
        $value = (float) $amount;
        $sign = $value < 0 ? '-' : '';
        $formatted = number_format(abs($value), 2);

        if (in_array($code, self::SUFFIX_CURRENCIES, true)) {
            return $sign.$formatted.' '.$symbol;
        }

        return $sign.$symbol.$formatted;
    }
}

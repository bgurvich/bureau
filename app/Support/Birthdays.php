<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Contact;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Birthday helpers — compute upcoming anniversaries from contacts.birthday.
 *
 * Year-unknown birthdays use the convention 1900-MM-DD; nextAnniversary()
 * drops the 1900 part and returns this-year's anniversary (or next-year's
 * if this year's has already passed). ageOn() returns null when the
 * stored year is 1900.
 */
final class Birthdays
{
    public const YEAR_UNKNOWN = 1900;

    /**
     * Contacts whose next birthday falls within the next $days days
     * (inclusive), sorted ascending by that next anniversary.
     *
     * @return Collection<int, Contact>
     */
    public static function upcoming(int $days = 30, ?CarbonInterface $today = null): Collection
    {
        $today = $today ? CarbonImmutable::instance($today)->startOfDay() : CarbonImmutable::today();
        $cutoff = $today->addDays($days);

        return Contact::query()
            ->whereNotNull('birthday')
            ->get()
            ->map(function (Contact $c) use ($today) {
                $c->setAttribute('_next_birthday', self::nextAnniversary($c->birthday, $today));

                return $c;
            })
            ->filter(function (Contact $c) use ($today, $cutoff) {
                $next = $c->getAttribute('_next_birthday');

                return $next && $next->greaterThanOrEqualTo($today) && $next->lessThanOrEqualTo($cutoff);
            })
            ->sortBy(fn (Contact $c) => $c->getAttribute('_next_birthday')->timestamp)
            ->values();
    }

    /**
     * Compute the next occurrence of this month/day relative to $today.
     * If today IS the birthday, today is returned (so same-day alerts
     * don't jump a year forward).
     */
    public static function nextAnniversary(CarbonInterface $birthday, ?CarbonInterface $today = null): CarbonImmutable
    {
        $today = $today ? CarbonImmutable::instance($today)->startOfDay() : CarbonImmutable::today();
        $thisYear = $today->setDate($today->year, (int) $birthday->month, (int) $birthday->day);

        return $thisYear->greaterThanOrEqualTo($today) ? $thisYear : $thisYear->addYear();
    }

    /** Current age on $on, or null when the stored year is the 1900 sentinel. */
    public static function ageOn(CarbonInterface $birthday, ?CarbonInterface $on = null): ?int
    {
        if ((int) $birthday->year === self::YEAR_UNKNOWN) {
            return null;
        }
        $on = $on ? CarbonImmutable::instance($on) : CarbonImmutable::today();

        return (int) $birthday->diffInYears($on);
    }
}

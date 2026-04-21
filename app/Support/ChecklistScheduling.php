<?php

namespace App\Support;

use App\Models\ChecklistRun;
use App\Models\ChecklistTemplate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Scheduling helper for checklist templates. Evaluates per-day "is this
 * ritual due today?" against the template's RFC-5545 RRULE (reuses the
 * Rrule helper from the bills subsystem). Also computes streaks — the
 * count of consecutive scheduled-and-completed days walking back from
 * today, bounded to keep reads cheap.
 */
class ChecklistScheduling
{
    private const STREAK_LOOKBACK_DAYS = 365;

    public static function isScheduledOn(
        ChecklistTemplate $template,
        CarbonInterface|DateTimeInterface|string $date,
    ): bool {
        $d = CarbonImmutable::parse($date)->startOfDay();

        if (! $template->active) {
            return false;
        }

        $dtstart = $template->dtstart
            ? CarbonImmutable::parse($template->dtstart)->startOfDay()
            : null;
        if ($dtstart && $d->lt($dtstart)) {
            return false;
        }

        $paused = $template->paused_until
            ? CarbonImmutable::parse($template->paused_until)->startOfDay()
            : null;
        if ($paused && $d->lte($paused)) {
            return false;
        }

        $rrule = trim((string) $template->rrule);
        if ($rrule === '') {
            // No recurrence rule → treat as always applicable (anytime).
            return true;
        }

        $dates = Rrule::expand($dtstart ?? $d, $rrule, $d);
        foreach ($dates as $candidate) {
            if ($candidate->toDateString() === $d->toDateString()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Active templates in the current household scheduled for $date.
     * Ordered by sort_order, then time_of_day bucket, then name.
     *
     * @return Collection<int, ChecklistTemplate>
     */
    public static function templatesScheduledOn(
        CarbonInterface|DateTimeInterface|string $date,
    ): Collection {
        $d = CarbonImmutable::parse($date)->startOfDay();
        $ds = $d->toDateString();

        return ChecklistTemplate::with(['items' => fn ($q) => $q->orderBy('position')])
            ->where('active', true)
            ->where(function ($q) use ($ds) {
                $q->whereNull('paused_until')->orWhere('paused_until', '<', $ds);
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (ChecklistTemplate $t) => self::isScheduledOn($t, $d))
            ->values();
    }

    /**
     * Count consecutive scheduled days ending at $today that have a run
     * with completed_at set. Days on which the template isn't scheduled
     * are skipped (don't break or extend the streak). Stops at the first
     * scheduled-but-missing day or at the lookback cap.
     */
    public static function streak(ChecklistTemplate $template, ?CarbonInterface $today = null): int
    {
        $cursor = CarbonImmutable::parse($today ?? now())->startOfDay();
        $dtstart = $template->dtstart
            ? CarbonImmutable::parse($template->dtstart)->startOfDay()
            : null;

        $completedDates = ChecklistRun::where('checklist_template_id', $template->id)
            ->whereNotNull('completed_at')
            ->pluck('run_date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->flip()
            ->all();

        $streak = 0;
        for ($i = 0; $i < self::STREAK_LOOKBACK_DAYS; $i++) {
            $day = $cursor->subDays($i);
            if ($dtstart && $day->lt($dtstart)) {
                break;
            }
            if (! self::isScheduledOn($template, $day)) {
                continue;
            }
            if (! isset($completedDates[$day->toDateString()])) {
                break;
            }
            $streak++;
        }

        return $streak;
    }
}

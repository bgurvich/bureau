<?php

namespace App\Support;

use App\Models\Reminder;
use App\Models\SavingsGoal;
use Illuminate\Support\Collection;

/**
 * Scans active savings goals for milestone crossings. A milestone fires
 * exactly once — tracked via `milestones_hit` JSON array on the goal so
 * toggling between progress values doesn't re-notify.
 *
 * Each crossing creates a Reminder row (user's configured channel picks up
 * delivery) rather than sending email directly — consistent with the rest
 * of Secretaire's notification pipeline.
 */
class SavingsMilestoneTracker
{
    public const MILESTONES = [25, 50, 75, 100];

    /**
     * Check every active goal and fire reminders for newly-crossed
     * milestones. Returns the number of reminders created.
     */
    public static function sweep(): int
    {
        $created = 0;
        SavingsGoal::withoutGlobalScopes()
            ->where('state', 'active')
            ->chunk(200, function ($goals) use (&$created) {
                /** @var Collection<int, SavingsGoal> $goals */
                foreach ($goals as $goal) {
                    $created += self::checkGoal($goal);
                }
            });

        return $created;
    }

    public static function checkGoal(SavingsGoal $goal): int
    {
        $ratio = $goal->progressRatio();
        $hit = is_array($goal->milestones_hit) ? $goal->milestones_hit : [];
        $newMilestones = [];

        foreach (self::MILESTONES as $pct) {
            if ($ratio >= $pct / 100 && ! in_array($pct, $hit, true)) {
                $newMilestones[] = $pct;
            }
        }

        if ($newMilestones === []) {
            return 0;
        }

        foreach ($newMilestones as $pct) {
            Reminder::create([
                'household_id' => $goal->household_id,
                'title' => $pct === 100
                    ? __(':name reached its target!', ['name' => $goal->name])
                    : __(':name crossed :pct%', ['name' => $goal->name, 'pct' => $pct]),
                'remind_at' => now(),
                'channel' => 'in_app',
                'state' => 'pending',
                'remindable_type' => SavingsGoal::class,
                'remindable_id' => $goal->id,
            ]);
        }

        $goal->forceFill(['milestones_hit' => array_merge($hit, $newMilestones)])->save();

        return count($newMilestones);
    }
}

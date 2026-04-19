<?php

namespace App\Console\Commands;

use App\Mail\ReminderMail;
use App\Models\Reminder;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class FireReminders extends Command
{
    protected $signature = 'reminders:fire
        {--dry-run : Show what would fire without sending}';

    protected $description = 'Find pending reminders past their remind_at and dispatch them on the configured channel (email today; slack/telegram/sms/push deferred).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Avoid the household scope: reminders span all households for the worker.
        $reminders = Reminder::withoutGlobalScopes()
            ->with('user:id,name,email')
            ->where('state', 'pending')
            ->where('remind_at', '<=', now())
            ->whereNull('fired_at')
            ->orderBy('remind_at')
            ->limit(500)
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($reminders as $reminder) {
            $channel = $reminder->channel ?: 'in_app';

            if (! $this->userWants($reminder, $channel)) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("  would fire #{$reminder->id} [{$channel}] {$reminder->title}");
                $sent++;

                continue;
            }

            try {
                match ($channel) {
                    'email' => $this->fireEmail($reminder),
                    // slack / sms / telegram / push / in_app: delivery deferred,
                    // but still mark fired so the radar isn't stuck showing it
                    default => null,
                };

                $reminder->forceFill([
                    'state' => 'fired',
                    'fired_at' => now(),
                ])->save();
                $sent++;
            } catch (\Throwable $e) {
                $this->warn("  #{$reminder->id} failed: {$e->getMessage()}");
            }
        }

        $this->info("  Fired {$sent}, skipped {$skipped}".($dryRun ? ' (dry run)' : '').'.');

        return self::SUCCESS;
    }

    private function userWants(Reminder $reminder, string $channel): bool
    {
        if (! $reminder->user_id) {
            // Household-wide reminders: default to fire. Explicit opt-out is
            // per-user, so no user = no per-user opt-out.
            return true;
        }

        $kind = $reminder->remindable_type
            ? strtolower(class_basename((string) $reminder->remindable_type)).'_reminder'
            : 'generic_reminder';

        /** @var UserNotificationPreference|null $pref */
        $pref = UserNotificationPreference::query()
            ->where('user_id', $reminder->user_id)
            ->where('household_id', $reminder->household_id)
            ->where('kind', $kind)
            ->where('channel', $channel)
            ->first();

        // Default = enabled. Row only exists if user has explicitly chosen.
        return $pref ? (bool) $pref->enabled : true;
    }

    private function fireEmail(Reminder $reminder): void
    {
        /** @var User|null $user */
        $user = $reminder->user()->first();
        $to = $user?->email;
        if (! $to) {
            return;
        }

        Mail::to($to)->send(new ReminderMail($reminder));
    }
}

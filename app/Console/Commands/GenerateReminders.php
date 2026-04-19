<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Household;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Reminder;
use App\Models\Task;
use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateReminders extends Command
{
    protected $signature = 'reminders:generate
        {--horizon=60 : Days ahead to look for remindable items}
        {--household= : Restrict to a single household id}';

    protected $description = 'Create Reminder rows for upcoming date-bearing records (bills, expiring docs, contracts, tasks, appointments). Idempotent — skips entries that already have a matching reminder.';

    public function handle(): int
    {
        $horizonDays = max(1, (int) $this->option('horizon'));
        $householdFilter = $this->option('household');
        $now = CarbonImmutable::now();
        $until = $now->addDays($horizonDays);

        $households = Household::query()
            ->when($householdFilter, fn ($q) => $q->where('id', $householdFilter))
            ->get();

        $created = 0;

        foreach ($households as $household) {
            CurrentHousehold::set($household);

            $created += $this->seedFromBills($now, $until);
            $created += $this->seedFromDocuments($now, $until);
            $created += $this->seedFromContracts($now, $until);
            $created += $this->seedFromTasks($now);
            $created += $this->seedFromAppointments($now);
        }

        $this->info("  Created {$created} reminder(s).");

        return self::SUCCESS;
    }

    /** Projections within horizon → reminder at due_on - rule.lead_days (default 3). */
    private function seedFromBills(CarbonImmutable $now, CarbonImmutable $until): int
    {
        $rows = RecurringProjection::with('rule')
            ->whereIn('status', ['projected', 'overdue'])
            ->whereBetween('due_on', [$now->toDateString(), $until->toDateString()])
            ->get();

        $count = 0;
        foreach ($rows as $p) {
            /** @var RecurringRule|null $rule */
            $rule = $p->rule;
            $lead = (int) ($rule->lead_days ?? 3);
            $remindAt = CarbonImmutable::parse($p->due_on)->subDays($lead)->setTime(8, 0);
            if ($remindAt->lt($now)) {
                continue; // Already past; skip rather than spam a stale reminder.
            }

            $count += $this->upsert(
                remindable: $p,
                title: __('Bill due: :title', ['title' => $rule->title ?? __('Scheduled item')]),
                remindAt: $remindAt,
                channel: 'email',
            );
        }

        return $count;
    }

    /** Documents expiring in ≤30d → reminder 30d + 7d out. */
    private function seedFromDocuments(CarbonImmutable $now, CarbonImmutable $until): int
    {
        $docs = Document::whereNotNull('expires_on')
            ->whereBetween('expires_on', [$now->toDateString(), $until->toDateString()])
            ->get();

        $count = 0;
        foreach ($docs as $d) {
            foreach ([30, 7] as $lead) {
                $remindAt = CarbonImmutable::parse($d->expires_on)->subDays($lead)->setTime(9, 0);
                if ($remindAt->lt($now)) {
                    continue;
                }
                $count += $this->upsert(
                    remindable: $d,
                    title: __('Document expiring: :label', ['label' => $d->label ?: ucfirst((string) $d->kind)]),
                    remindAt: $remindAt,
                    channel: 'email',
                );
            }
        }

        return $count;
    }

    /** Contracts ending in horizon → reminder at ends_on - renewal_notice_days. */
    private function seedFromContracts(CarbonImmutable $now, CarbonImmutable $until): int
    {
        $contracts = Contract::whereIn('state', ['active', 'expiring'])
            ->whereNotNull('ends_on')
            ->whereBetween('ends_on', [$now->toDateString(), $until->toDateString()])
            ->get();

        $count = 0;
        foreach ($contracts as $c) {
            $lead = (int) ($c->renewal_notice_days ?? 14);
            $remindAt = CarbonImmutable::parse($c->ends_on)->subDays($lead)->setTime(9, 0);
            if ($remindAt->lt($now)) {
                continue;
            }
            $count += $this->upsert(
                remindable: $c,
                title: __('Contract ending: :title', ['title' => $c->title]),
                remindAt: $remindAt,
                channel: 'email',
            );
        }

        return $count;
    }

    /** Open tasks with due_at in next 24h → reminder 1h ahead. */
    private function seedFromTasks(CarbonImmutable $now): int
    {
        $tasks = Task::whereIn('state', ['open', 'waiting'])
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$now, $now->addDay()])
            ->get();

        $count = 0;
        foreach ($tasks as $t) {
            $remindAt = CarbonImmutable::parse($t->due_at)->subHour();
            if ($remindAt->lt($now)) {
                continue;
            }
            $count += $this->upsert(
                remindable: $t,
                title: __('Task due: :title', ['title' => $t->title]),
                remindAt: $remindAt,
                channel: 'email',
                userId: $t->assigned_user_id,
            );
        }

        return $count;
    }

    /** Appointments in next 24h → reminder 1h ahead. */
    private function seedFromAppointments(CarbonImmutable $now): int
    {
        $appts = Appointment::where('state', 'scheduled')
            ->whereBetween('starts_at', [$now, $now->addDay()])
            ->get();

        $count = 0;
        foreach ($appts as $a) {
            $remindAt = CarbonImmutable::parse($a->starts_at)->subHour();
            if ($remindAt->lt($now)) {
                continue;
            }
            $count += $this->upsert(
                remindable: $a,
                title: __('Appointment: :purpose', ['purpose' => $a->purpose ?: 'Appointment']),
                remindAt: $remindAt,
                channel: 'email',
            );
        }

        return $count;
    }

    /**
     * Idempotent insert. Skips if a reminder already exists for this record
     * at this approximate time (within 6 hours), so the daily generator never
     * stacks duplicates on top of prior runs.
     */
    private function upsert(
        object $remindable,
        string $title,
        CarbonImmutable $remindAt,
        string $channel,
        ?int $userId = null,
    ): int {
        $existing = Reminder::where('remindable_type', $remindable::class)
            ->where('remindable_id', $remindable->id)
            ->where('channel', $channel)
            ->where('remind_at', '>=', $remindAt->subHours(6))
            ->where('remind_at', '<=', $remindAt->addHours(6))
            ->exists();

        if ($existing) {
            return 0;
        }

        Reminder::create([
            'user_id' => $userId,
            'remindable_type' => $remindable::class,
            'remindable_id' => $remindable->id,
            'title' => $title,
            'remind_at' => $remindAt,
            'channel' => $channel,
            'state' => 'pending',
        ]);

        return 1;
    }
}

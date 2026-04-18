<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Support\CurrentHousehold;
use App\Support\Rrule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateRecurringProjections extends Command
{
    protected $signature = 'recurring:project
        {--horizon=90 : Days into the future to project}
        {--backfill=30 : Days of backfill to keep as overdue}
        {--household= : Only project rules for this household id}';

    protected $description = 'Expand active recurring_rules into recurring_projections rows for the time/money radars.';

    public function handle(): int
    {
        $horizonDays = max(1, (int) $this->option('horizon'));
        $backfillDays = max(0, (int) $this->option('backfill'));
        $householdFilter = $this->option('household');

        $from = CarbonImmutable::today()->subDays($backfillDays);
        $to = CarbonImmutable::today()->addDays($horizonDays);

        $households = Household::query()
            ->when($householdFilter, fn ($q) => $q->where('id', $householdFilter))
            ->get();

        $totalCreated = 0;
        $totalPromoted = 0;

        foreach ($households as $household) {
            CurrentHousehold::set($household);

            $rules = RecurringRule::query()->where('active', true)->get();

            foreach ($rules as $rule) {
                $ruleStart = $rule->dtstart ? CarbonImmutable::parse($rule->dtstart) : $from;
                $effectiveStart = $ruleStart->gt($from) ? $ruleStart : $from;

                $dates = Rrule::expand(
                    dtstart: $ruleStart,
                    rrule: $rule->rrule,
                    horizon: $to,
                    until: $rule->until,
                );

                foreach ($dates as $date) {
                    if ($date->lt($effectiveStart)) {
                        continue;
                    }

                    [$created, $promoted] = $this->upsertProjection($rule, $date);
                    $totalCreated += $created;
                    $totalPromoted += $promoted;
                }
            }
        }

        $this->info(sprintf(
            'Projected %d new occurrence(s); promoted %d to overdue across %d household(s).',
            $totalCreated,
            $totalPromoted,
            $households->count(),
        ));

        return self::SUCCESS;
    }

    /** @return array{0:int,1:int} [created, promotedToOverdue] */
    private function upsertProjection(RecurringRule $rule, CarbonImmutable $date): array
    {
        $issuedOn = $date->toDateString();
        $offsetDays = max(0, (int) ($rule->due_offset_days ?? 0));
        $dueDate = $date->addDays($offsetDays);
        $dueOn = $dueDate->toDateString();
        $isPast = $dueDate->lt(CarbonImmutable::today());

        // whereDate keeps the comparison independent of how the driver stores
        // DATE columns (MariaDB strips the time; SQLite keeps it in the cast).
        $existing = RecurringProjection::where('rule_id', $rule->id)
            ->whereDate('due_on', $dueOn)
            ->first();

        if ($existing) {
            if ($existing->status === 'projected' && $isPast) {
                $existing->update(['status' => 'overdue']);

                return [0, 1];
            }

            return [0, 0];
        }

        RecurringProjection::create([
            'rule_id' => $rule->id,
            'due_on' => $dueOn,
            'issued_on' => $issuedOn,
            'amount' => $rule->amount,
            'currency' => $rule->currency,
            'status' => $isPast ? 'overdue' : 'projected',
            'autopay' => (bool) ($rule->autopay ?? false),
        ]);

        return [1, 0];
    }
}

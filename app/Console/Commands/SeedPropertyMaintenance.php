<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\RecurringRule;
use App\Support\CurrentHousehold;
use Illuminate\Console\Command;

class SeedPropertyMaintenance extends Command
{
    protected $signature = 'property:seed-maintenance
        {property : Property id}
        {--dry-run : Show what would be created without writing}';

    protected $description = 'Seed recurring home-maintenance rules (HVAC filter, gutters, etc.) against a Property. Idempotent — skips rules whose title already exists for that property.';

    /**
     * Template catalog. DTSTART defaults to today for monthly cadence rules and
     * to "next typical service month" for yearly / biannual ones so the first
     * projection lands on a realistic date. Seasons are northern-hemisphere;
     * edit locally if you live south of the equator.
     *
     * @return array<int, array{title: string, rrule: string, dtstart_offset_days: int, lead_days: int}>
     */
    private function templates(): array
    {
        return [
            ['title' => 'HVAC air filter replacement', 'rrule' => 'FREQ=MONTHLY;INTERVAL=2', 'dtstart_offset_days' => 7, 'lead_days' => 3],
            ['title' => 'Whole-house water filter cartridge', 'rrule' => 'FREQ=MONTHLY;INTERVAL=4', 'dtstart_offset_days' => 14, 'lead_days' => 7],
            ['title' => 'Fridge water / ice filter', 'rrule' => 'FREQ=MONTHLY;INTERVAL=6', 'dtstart_offset_days' => 30, 'lead_days' => 7],
            ['title' => 'Furnace annual service', 'rrule' => 'FREQ=YEARLY;BYMONTH=9', 'dtstart_offset_days' => 0, 'lead_days' => 21],
            ['title' => 'Air-conditioning annual service', 'rrule' => 'FREQ=YEARLY;BYMONTH=4', 'dtstart_offset_days' => 0, 'lead_days' => 21],
            ['title' => 'Water heater flush', 'rrule' => 'FREQ=YEARLY;BYMONTH=6', 'dtstart_offset_days' => 0, 'lead_days' => 14],
            ['title' => 'Dryer vent cleaning', 'rrule' => 'FREQ=YEARLY;BYMONTH=10', 'dtstart_offset_days' => 0, 'lead_days' => 14],
            ['title' => 'Gutter cleaning', 'rrule' => 'FREQ=YEARLY;BYMONTH=4,10', 'dtstart_offset_days' => 0, 'lead_days' => 14],
            ['title' => 'Roof inspection', 'rrule' => 'FREQ=YEARLY;BYMONTH=5', 'dtstart_offset_days' => 0, 'lead_days' => 30],
            ['title' => 'Smoke / CO detector battery + test', 'rrule' => 'FREQ=YEARLY;BYMONTH=11', 'dtstart_offset_days' => 0, 'lead_days' => 14],
            ['title' => 'Chimney sweep (if wood-burning)', 'rrule' => 'FREQ=YEARLY;BYMONTH=9', 'dtstart_offset_days' => 0, 'lead_days' => 14],
            ['title' => 'Pest control', 'rrule' => 'FREQ=MONTHLY;INTERVAL=3', 'dtstart_offset_days' => 30, 'lead_days' => 7],
            ['title' => 'Septic pump-out', 'rrule' => 'FREQ=YEARLY;INTERVAL=3;BYMONTH=5', 'dtstart_offset_days' => 0, 'lead_days' => 30],
            ['title' => 'Deck / fence staining', 'rrule' => 'FREQ=YEARLY;INTERVAL=2;BYMONTH=6', 'dtstart_offset_days' => 0, 'lead_days' => 30],
        ];
    }

    public function handle(): int
    {
        $propertyId = (int) $this->argument('property');
        $dryRun = (bool) $this->option('dry-run');

        $property = Property::find($propertyId);
        if (! $property) {
            $this->error("Property id {$propertyId} not found.");

            return self::FAILURE;
        }

        CurrentHousehold::set($property->household);

        $existing = RecurringRule::where('subject_type', Property::class)
            ->where('subject_id', $property->id)
            ->pluck('title')
            ->map(fn ($t) => mb_strtolower(trim((string) $t)))
            ->all();

        $created = 0;
        $skipped = 0;
        $today = now();

        foreach ($this->templates() as $t) {
            if (in_array(mb_strtolower($t['title']), $existing, true)) {
                $skipped++;

                continue;
            }

            $this->line("  + {$t['title']}  ({$t['rrule']})");

            if ($dryRun) {
                $created++;

                continue;
            }

            RecurringRule::create([
                'kind' => 'maintenance',
                'title' => $t['title'],
                'rrule' => $t['rrule'],
                'dtstart' => $today->copy()->addDays($t['dtstart_offset_days'])->toDateString(),
                'subject_type' => Property::class,
                'subject_id' => $property->id,
                'lead_days' => $t['lead_days'],
                'active' => true,
            ]);
            $created++;
        }

        $this->info("  Created {$created}, skipped {$skipped}"
            .($dryRun ? ' (dry run — nothing written)' : '')
            .'. Run `php artisan recurring:project` to materialize projections.');

        return self::SUCCESS;
    }
}

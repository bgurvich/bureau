<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Pet;
use App\Models\PetVaccination;

/**
 * Canonical vaccine list per species. Seeded as placeholder
 * PetVaccination rows (with null administered_on) when a new Pet is
 * created, so the user lands on the pet's detail with the expected
 * vaccines already enumerated — they fill in dates + scan media as
 * records come back from the vet.
 *
 * Split into "required" (legally mandated in most jurisdictions or
 * universally recommended) vs "situational" (depends on lifestyle
 * like boarding/tick-risk areas). Only required rows are seeded —
 * situational ones can be added ad-hoc so the list doesn't look like
 * a pile of todos the user will never cross off.
 */
final class PetVaccineTemplates
{
    /**
     * @return array<int, array{name: string, notes: string, required: bool}>
     */
    public static function forSpecies(string $species): array
    {
        return match (strtolower(trim($species))) {
            'dog' => [
                ['name' => 'Rabies', 'notes' => 'Legally required in most jurisdictions; 1–3 year validity depending on product.', 'required' => true],
                ['name' => 'DHPP', 'notes' => 'Distemper / Adenovirus / Parvo / Parainfluenza combo.', 'required' => true],
                ['name' => 'Leptospirosis', 'notes' => 'Regional — recommended in wet or rural areas.', 'required' => true],
                ['name' => 'Bordetella', 'notes' => 'Required by boarding / daycare / grooming facilities.', 'required' => false],
                ['name' => 'Lyme', 'notes' => 'Tick-risk regions (Northeast, Midwest).', 'required' => false],
                ['name' => 'Canine Influenza', 'notes' => 'Situational — social dogs, kennel outbreaks.', 'required' => false],
            ],
            'cat' => [
                ['name' => 'Rabies', 'notes' => 'Legally required in most jurisdictions.', 'required' => true],
                ['name' => 'FVRCP', 'notes' => 'Feline viral rhinotracheitis / calicivirus / panleukopenia combo.', 'required' => true],
                ['name' => 'FeLV', 'notes' => 'Feline leukemia — especially outdoor or multi-cat households.', 'required' => false],
            ],
            default => [],
        };
    }

    /**
     * Create placeholder PetVaccination rows for the required vaccines
     * on this species. Skips anything the pet already has (idempotent
     * so you can re-run for a species template update later without
     * duplicating rows).
     *
     * @return int number of rows created
     */
    public static function seedRequiredFor(Pet $pet): int
    {
        $existing = $pet->vaccinations()->pluck('vaccine_name')->map(fn ($v) => mb_strtolower((string) $v))->all();

        $created = 0;
        foreach (self::forSpecies((string) $pet->species) as $template) {
            if (! $template['required']) {
                continue;
            }
            if (in_array(mb_strtolower($template['name']), $existing, true)) {
                continue;
            }
            PetVaccination::create([
                'pet_id' => $pet->id,
                'vaccine_name' => $template['name'],
                'notes' => $template['notes'],
            ]);
            $created++;
        }

        return $created;
    }
}

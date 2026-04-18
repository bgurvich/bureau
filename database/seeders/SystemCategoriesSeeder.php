<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Household;
use Illuminate\Database\Seeder;

/**
 * System-required categories — slugs that the app's engines look for by name.
 * Separate from the friendly starter set in DatabaseSeeder::seedCategories()
 * so it can be reseeded on upgrade without clobbering user-added children.
 *
 * Runs idempotently per (household_id, slug) via firstOrCreate.
 *
 * Usage:
 *   php artisan db:seed --class=SystemCategoriesSeeder
 */
class SystemCategoriesSeeder extends Seeder
{
    /**
     * @var array<int, array{kind: string, slug: string, name: string}>
     */
    private const SYSTEM = [
        // Effective-rate engine reads these (App\Support\EffectiveRate).
        ['kind' => 'expense', 'slug' => 'interest-paid', 'name' => 'Interest paid'],
        ['kind' => 'income', 'slug' => 'interest-earned', 'name' => 'Interest earned'],
    ];

    public function run(): void
    {
        Household::query()->orderBy('id')->chunk(50, function ($households) {
            foreach ($households as $household) {
                foreach (self::SYSTEM as $row) {
                    Category::firstOrCreate(
                        [
                            'household_id' => $household->id,
                            'slug' => $row['slug'],
                        ],
                        [
                            'kind' => $row['kind'],
                            'name' => $row['name'],
                        ],
                    );
                }
            }
        });
    }
}

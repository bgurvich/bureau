<?php

namespace Database\Seeders;

use App\Models\Household;
use App\Models\MediaFolder;
use App\Support\MediaFolders;
use Illuminate\Database\Seeder;

/**
 * Canonical per-household Media folders that every capture/import
 * surface routes into — Inventory, Receipts, Bills, Documents, Post,
 * Statements. Seeded so the Records → Media folder filter renders
 * from day one, not only after the first capture. Idempotent per
 * (household_id, path) via firstOrCreate.
 *
 * Usage:
 *   php artisan db:seed --class=SystemMediaFoldersSeeder
 *
 * Also runs as part of DatabaseSeeder on a fresh install.
 */
class SystemMediaFoldersSeeder extends Seeder
{
    public function run(): void
    {
        Household::query()->orderBy('id')->chunk(50, function ($households) {
            foreach ($households as $household) {
                foreach (MediaFolders::all() as $slug => $label) {
                    MediaFolder::withoutGlobalScope('household')->firstOrCreate(
                        [
                            'household_id' => $household->id,
                            'path' => $slug,
                        ],
                        [
                            'label' => $label,
                            'active' => true,
                        ],
                    );
                }
            }
        });
    }
}

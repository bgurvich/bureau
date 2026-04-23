<?php

namespace Database\Seeders;

use App\Models\Household;
use App\Models\User;
use App\Support\CurrentHousehold;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Minimum bootstrap every fresh install needs:
 *   - One Household (the "Secretaire" default).
 *   - One owner User (credentials read from env, placeholder otherwise).
 *   - Starter + system categories for that household.
 *
 * Demo rows (accounts, transactions, tasks, etc.) are shipped via
 * DemoDataSeeder. Run explicitly in a dev / demo environment:
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Keeping demo data out of the default seeder means production
 * `php artisan db:seed --force` is safe to run on a real deploy without
 * dropping fake rows into a user-facing install.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $household = Household::firstOrCreate(
                ['name' => 'Secretaire'],
                ['default_currency' => 'USD']
            );

            // Owner creds default to placeholders; the user edits them after
            // first login. Config entries let env tune this without code
            // changes (add SEED_OWNER_EMAIL= etc. in .env if desired).
            $ownerEmail = (string) config('secretaire.seed.owner_email', 'owner@secretaire.local');
            $ownerName = (string) config('secretaire.seed.owner_name', 'Owner');
            $ownerPassword = (string) config('secretaire.seed.owner_password', 'change-me');

            $user = User::firstOrCreate(
                ['email' => $ownerEmail],
                [
                    'name' => $ownerName,
                    'password' => Hash::make($ownerPassword),
                    'default_household_id' => $household->id,
                ]
            );

            if ($user->default_household_id !== $household->id) {
                $user->forceFill(['default_household_id' => $household->id])->save();
            }

            $household->users()->syncWithoutDetaching([
                $user->id => ['role' => 'owner', 'joined_at' => now()],
            ]);

            CurrentHousehold::set($household);

            $this->call(StarterCategoriesSeeder::class);
            $this->call(SystemCategoriesSeeder::class);
            $this->call(SystemMediaFoldersSeeder::class);
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Switch the user theme default from `system` to `dusk`. Also backfills
 * any row still on the old `system` default so the existing single user
 * flips to dusk without having to re-open the profile form. Users who
 * had explicitly picked light/dark/retro/dusk are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY theme ENUM('light', 'dark', 'system', 'retro', 'dusk') NOT NULL DEFAULT 'dusk'");
        DB::table('users')->where('theme', 'system')->update(['theme' => 'dusk']);
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY theme ENUM('light', 'dark', 'system', 'retro', 'dusk') NOT NULL DEFAULT 'system'");
    }
};

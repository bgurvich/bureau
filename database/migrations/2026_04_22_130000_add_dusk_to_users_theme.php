<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend users.theme enum with the new `dusk` value. Dusk is a warm-stone
 * midtone palette — see `resources/css/app.css` for the token remap.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY theme ENUM('light', 'dark', 'system', 'retro', 'dusk') NOT NULL DEFAULT 'system'");
    }

    public function down(): void
    {
        // Roll any rows on 'dusk' back to 'system' before narrowing the
        // enum; otherwise MariaDB rejects the ALTER.
        DB::table('users')->where('theme', 'dusk')->update(['theme' => 'system']);
        DB::statement("ALTER TABLE users MODIFY theme ENUM('light', 'dark', 'system', 'retro') NOT NULL DEFAULT 'system'");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add the `dusk-comfort` theme — a dusk-based variant that floors
 * every text-xs / arbitrary-px small utility at text-sm so captions,
 * chips, and pills stay legible in long sessions. Palette identical
 * to dusk (shared CSS rules in `resources/css/app.css`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY theme ENUM('light', 'dark', 'system', 'retro', 'dusk', 'dusk-comfort') NOT NULL DEFAULT 'dusk'");
    }

    public function down(): void
    {
        DB::table('users')->where('theme', 'dusk-comfort')->update(['theme' => 'dusk']);
        DB::statement("ALTER TABLE users MODIFY theme ENUM('light', 'dark', 'system', 'retro', 'dusk') NOT NULL DEFAULT 'dusk'");
    }
};

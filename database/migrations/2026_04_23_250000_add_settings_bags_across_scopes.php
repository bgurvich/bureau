<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settings bags at three scopes:
 *   - App:       singleton row in app_settings (id=1, data json).
 *   - Household: reuse existing households.data json (already exists).
 *   - User:      new users.settings json.
 *
 * All three are free-form key/value bags — the Settings helper reads
 * with dot-path + default fallback. Schema-less on purpose: these are
 * knobs the user tunes, not structured domain data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('theme');
        });

        // Seed the singleton row so Settings::app() always has a target.
        DB::table('app_settings')->insert([
            'id' => 1,
            'data' => json_encode(new stdClass),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
        Schema::dropIfExists('app_settings');
    }
};

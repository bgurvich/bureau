<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MariaDB/MySQL: ALTER the enum. SQLite (tests): recreate column.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE users MODIFY theme ENUM('light', 'dark', 'system', 'retro') NOT NULL DEFAULT 'system'");

            return;
        }

        // SQLite path — column is TEXT-backed, drop and re-add with new CHECK.
        Schema::table('users', function ($table) {
            $table->dropColumn('theme');
        });

        Schema::table('users', function ($table) {
            $table->string('theme', 16)->default('system');
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("UPDATE users SET theme = 'dark' WHERE theme = 'retro'");
            DB::statement("ALTER TABLE users MODIFY theme ENUM('light', 'dark', 'system') NOT NULL DEFAULT 'system'");

            return;
        }

        Schema::table('users', function ($table) {
            $table->dropColumn('theme');
        });
        Schema::table('users', function ($table) {
            $table->string('theme', 16)->default('system');
        });
    }
};

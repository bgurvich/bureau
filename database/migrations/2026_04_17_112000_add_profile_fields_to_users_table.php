<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 10)->default('en')->after('email');
            $table->string('timezone', 64)->default('UTC')->after('locale');
            $table->string('date_format', 32)->default('Y-m-d')->after('timezone');
            $table->string('time_format', 16)->default('H:i')->after('date_format');
            $table->unsignedTinyInteger('week_starts_on')->default(0)->after('time_format'); // 0=Sun
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locale', 'timezone', 'date_format', 'time_format', 'week_starts_on']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goals gain a finite/infinite split. Finite (target) goals keep the
 * target_value + target_date pacing path; infinite (direction) goals
 * drop targets entirely and use cadence_days + last_reflected_at to
 * drive "you haven't touched this in a while" nudges.
 *
 * target_value becomes nullable so direction goals can store the title
 * + category + notes without a meaningless 0 target.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            // target | direction
            $table->string('mode', 16)->default('target')->after('category');
            // Direction-goal nudge interval. Null = no nudge.
            $table->unsignedSmallInteger('cadence_days')->nullable()->after('target_date');
            // Direction-goal "last time you opened this and updated
            // notes or bumped a counter." Drives the staleness tile.
            $table->timestamp('last_reflected_at')->nullable()->after('cadence_days');
        });

        // MySQL/MariaDB needs change() with doctrine/dbal; using raw
        // ALTER keeps the migration independent of that dependency.
        Schema::table('goals', function (Blueprint $table) {
            $table->decimal('target_value', 14, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->dropColumn(['mode', 'cadence_days', 'last_reflected_at']);
        });

        Schema::table('goals', function (Blueprint $table) {
            $table->decimal('target_value', 14, 2)->nullable(false)->change();
        });
    }
};

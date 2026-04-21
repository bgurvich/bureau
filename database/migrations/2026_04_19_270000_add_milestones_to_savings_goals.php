<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track which milestones (25/50/75/100%) have fired so we don't re-notify
 * every time the user reloads the page. JSON array of int percentages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->json('milestones_hit')->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->dropColumn('milestones_hit');
        });
    }
};

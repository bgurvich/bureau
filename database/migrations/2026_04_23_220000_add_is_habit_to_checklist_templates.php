<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Habit flag on checklist_templates. A habit is a checklist with just
 * one-or-few items that the user runs on a recurring cadence and
 * cares about streak continuity for. Same engine underneath
 * (ChecklistRun per run_date + ChecklistScheduling::streak()), but
 * rendered separately from the morning/evening rituals so a "meditate
 * 10 min daily" target doesn't get lost in a big ritual list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->boolean('is_habit')->default(false)->after('active');
            $table->index(['household_id', 'is_habit', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'is_habit', 'active']);
            $table->dropColumn('is_habit');
        });
    }
};

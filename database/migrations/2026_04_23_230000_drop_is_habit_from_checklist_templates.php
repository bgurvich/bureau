<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the is_habit flag. The habit-vs-checklist split is now derived
 * from the template's rrule: recurring templates (FREQ=DAILY/WEEKLY/…,
 * not COUNT=1) surface as habits with streak; one-off or null-rrule
 * templates surface as checklists (shopping, packing, onboarding).
 * No structural difference between a multi-item morning routine and a
 * single-item "meditate 10 min" — both are habits, the item count is
 * a presentation detail, not a distinction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'is_habit', 'active']);
            $table->dropColumn('is_habit');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->boolean('is_habit')->default(false)->after('active');
            $table->index(['household_id', 'is_habit', 'active']);
        });
    }
};

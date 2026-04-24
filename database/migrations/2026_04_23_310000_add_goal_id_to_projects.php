<?php

use App\Models\Goal;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make Goal a first-order parent of Project. The tree view's outer
 * grouping becomes Goal → Project → Task → Subtask. Projects without
 * a goal still work and render under an Unassigned-to-goal bucket.
 *
 * Backfills from any existing project_subjects rows whose
 * subject_type resolves to App\Models\Goal, then drops them from the
 * pivot so we don't double-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('goal_id')->nullable()->after('user_id')
                ->constrained('goals')->nullOnDelete();
            $table->index(['household_id', 'goal_id']);
        });

        $goalType = Relation::morphMap()['goal'] ?? Goal::class;
        $rows = DB::table('project_subjects')
            ->whereIn('subject_type', [$goalType, 'goal', Goal::class])
            ->get();
        foreach ($rows as $row) {
            DB::table('projects')->where('id', $row->project_id)
                ->update(['goal_id' => $row->subject_id]);
        }
        DB::table('project_subjects')
            ->whereIn('subject_type', [$goalType, 'goal', Goal::class])
            ->delete();
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'goal_id']);
            $table->dropConstrainedForeignId('goal_id');
        });
    }
};

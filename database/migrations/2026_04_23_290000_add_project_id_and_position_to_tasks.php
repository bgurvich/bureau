<?php

use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promote project-assignment on tasks from the polymorphic subject
 * pivot (task_subjects with subject_type=Project) to a direct FK.
 * Tree-view rendering + drag-drop reorder both want cheap group-by
 * and a stable position column; keeping it in task_subjects.position
 * forces a join per render and tangles the subjects semantics
 * ("what is this task about") with the hierarchy ("where does it
 * live"). The polymorphic subject link stays intact for anything
 * that wasn't a project — this migration only touches the pivot
 * rows whose subject_type maps to the Project model.
 *
 * position is scoped per (household_id, project_id) so the default
 * ordering in the tree comes out stable without any join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('parent_task_id')
                ->constrained('projects')->nullOnDelete();
            $table->unsignedInteger('position')->default(0)->after('priority');
            $table->index(['household_id', 'project_id', 'position']);
        });

        // Migrate existing polymorphic links: any task_subjects row whose
        // subject_type resolves to App\Models\Project promotes to the new
        // FK column, then the pivot row is removed so we don't double-up.
        $projectType = Relation::morphMap()['project'] ?? Project::class;
        $rows = DB::table('task_subjects')
            ->whereIn('subject_type', [$projectType, 'project', Project::class])
            ->get();
        foreach ($rows as $row) {
            DB::table('tasks')->where('id', $row->task_id)
                ->update(['project_id' => $row->subject_id]);
        }
        DB::table('task_subjects')
            ->whereIn('subject_type', [$projectType, 'project', Project::class])
            ->delete();
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'project_id', 'position']);
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('position');
        });
    }
};

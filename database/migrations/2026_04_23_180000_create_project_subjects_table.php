<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same shape as task_subjects / note_subjects / decision_subjects /
 * journal_entry_subjects. Projects gain polymorphic subject linkage so
 * a project can point at a Goal (or Vehicle, Property, Contact, etc.)
 * via the shared subject picker.
 *
 * Added specifically to support project→goal tethering — "build X to
 * achieve Y" — without introducing a separate project_goal table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_subjects', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedInteger('position')->default(0);

            $table->primary(['project_id', 'subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_subjects');
    }
};

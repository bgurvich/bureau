<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic many-to-many: decision → linked subjects. Same shape as
 * task_subjects / note_subjects / transaction_subjects / journal_entry_
 * subjects — the subject-ref concern trait walks these by convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_subjects', function (Blueprint $table) {
            $table->foreignId('decision_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->integer('position')->default(0);

            $table->primary(['decision_id', 'subject_type', 'subject_id'], 'decision_subjects_primary');
            $table->index(['subject_type', 'subject_id'], 'decision_subjects_reverse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_subjects');
    }
};

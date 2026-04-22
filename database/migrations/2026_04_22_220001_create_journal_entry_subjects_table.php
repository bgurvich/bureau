<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic many-to-many: a journal entry can mention one or more
 * subjects (people, pets, vehicles, properties, contracts…). Mirrors
 * the shape of task_subjects / note_subjects / transaction_subjects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_subjects', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->integer('position')->default(0);

            $table->primary(['journal_entry_id', 'subject_type', 'subject_id'], 'je_subjects_primary');
            $table->index(['subject_type', 'subject_id'], 'je_subjects_reverse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_subjects');
    }
};

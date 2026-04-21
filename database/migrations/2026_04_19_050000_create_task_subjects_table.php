<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_subjects', function (Blueprint $table) {
            // Polymorphic many-to-many: a task can subject multiple entities
            // (e.g. "renew insurance" → [Vehicle, Contract]) and an entity can
            // have multiple tasks. Same pattern as taggables/mediables.
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            $table->primary(['task_id', 'subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_subjects');
    }
};

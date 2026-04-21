<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_subjects', function (Blueprint $table) {
            // Polymorphic N:M for Transactions. A transaction can reference
            // multiple entities ("oil change" → Vehicle + Contact-mechanic).
            // Mirrors task_subjects / note_subjects shape exactly.
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedSmallInteger('position')->default(0);

            $table->primary(['transaction_id', 'subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_subjects');
    }
};

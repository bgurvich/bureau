<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('note_subjects', function (Blueprint $table) {
            // Polymorphic many-to-many, mirroring task_subjects. A note about
            // a mechanic's quote can reference both the Vehicle and the
            // Contact in one shot, and a vehicle accumulates many notes.
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            $table->primary(['note_id', 'subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('note_subjects');
    }
};

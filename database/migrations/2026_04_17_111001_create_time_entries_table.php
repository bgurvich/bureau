<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at');
            $table->unsignedInteger('duration_seconds'); // denormalized for fast rollups
            $table->date('activity_date'); // local date the entry is logged against (for reporting)
            $table->string('description')->nullable();
            $table->boolean('billable')->default(false);
            $table->boolean('billed')->default(false);
            $table->timestamps();
            $table->index(['household_id', 'user_id', 'activity_date']);
            $table->index(['household_id', 'project_id', 'activity_date']);
            $table->index(['household_id', 'billable', 'billed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};

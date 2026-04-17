<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_tracker_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('description')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('paused_at')->nullable();
            $table->unsignedInteger('accumulated_seconds')->default(0);
            $table->string('status', 10)->default('running'); // running|paused
            $table->timestamps();
            $table->unique('user_id'); // one live session per user
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_tracker_sessions');
    }
};

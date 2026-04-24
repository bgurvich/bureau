<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blocking predecessors for a task. A task is blocked as long as any
 * of the tasks in depends_on_task_id is not yet in state=done.
 * Many-to-many because real workflows often have multiple blockers
 * ("write the code" blocks "write the tests" blocks "deploy"; "write
 * the docs" also blocks "deploy").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['task_id', 'depends_on_task_id']);
            $table->index('depends_on_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
    }
};

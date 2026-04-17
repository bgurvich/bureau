<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedTinyInteger('priority')->default(3); // 1=high .. 5=low
            $table->string('state')->default('open'); // open|done|dropped|waiting
            $table->nullableMorphs('context'); // optional linkage to bill/contract/property/etc.
            $table->timestamps();
            $table->index(['household_id', 'state', 'due_at']);
            $table->index(['household_id', 'assigned_user_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};

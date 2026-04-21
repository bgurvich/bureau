<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // Coarse bucket for grouping + default reminder timing; not a clock time.
            $table->string('time_of_day', 16)->default('anytime');
            // RFC-5545 RRULE (optional). Null means "always scheduled, anytime".
            $table->string('rrule')->nullable();
            $table->date('dtstart');
            $table->date('paused_until')->nullable();
            $table->boolean('active')->default(true);
            $table->string('color', 16)->nullable();
            $table->string('icon', 32)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['household_id', 'active', 'time_of_day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_templates');
    }
};

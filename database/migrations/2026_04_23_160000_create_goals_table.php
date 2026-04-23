<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal goals — "read 20 books in 2026", "run 500 miles by Dec", "lose
 * 8 lbs by June". Distinct from SavingsGoal (which is money-only, tied
 * to accounts) and from ChecklistTemplate (which is a daily ritual, not
 * a target-and-deadline target).
 *
 * Progress tracking is intentionally simple: one current_value scalar
 * the user updates. An update log (goal_updates) adds fidelity but
 * belongs to a future pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');

            // Coarse bucket for the listing's filter chip row:
            // health | learning | creative | career | financial |
            // relationships | other. Schema stays free string.
            $table->string('category', 32)->default('other');

            $table->decimal('target_value', 14, 2);
            $table->decimal('current_value', 14, 2)->default(0);

            // "books", "mi", "kg", "hours", "€"… stored free so non-US
            // units and prose ("streak days") fit without enum churn.
            $table->string('unit', 32)->nullable();

            $table->date('started_on')->nullable();
            $table->date('target_date')->nullable();
            $table->date('achieved_on')->nullable();

            // active | paused | achieved | abandoned
            $table->string('status', 16)->default('active');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['household_id', 'status', 'target_date']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};

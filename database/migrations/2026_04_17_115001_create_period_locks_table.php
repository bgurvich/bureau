<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * period_locks — one row per household per lock. The most recent
 * locked_through_date wins; writes on/before that date are refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->date('locked_through');
            $table->string('reason', 255)->nullable();
            $table->foreignId('locked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->useCurrent();
            $table->timestamp('unlocked_at')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'unlocked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_locks');
    }
};

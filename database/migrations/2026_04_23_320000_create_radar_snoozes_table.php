<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user, per-household "quiet this radar tile until X" records.
 * Kind is the symbolic name of the radar signal (e.g. overdue_tasks).
 * Only one active snooze per (user, household, kind); upserting a new
 * snooze replaces the prior one. Expired rows get swept or just
 * ignored at read time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radar_snoozes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('signal_kind', 64);
            $table->timestamp('snoozed_until');
            $table->timestamps();

            $table->unique(['user_id', 'household_id', 'signal_kind']);
            $table->index(['user_id', 'household_id', 'snoozed_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radar_snoozes');
    }
};

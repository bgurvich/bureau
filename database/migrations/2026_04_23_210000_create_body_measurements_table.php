<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Body composition sample — weight + fat% + muscle% captured at one
 * point in time. Smart scales produce all three in one step, so the
 * table bundles them in one row rather than three meter_reading
 * siblings. Every field except measured_at is nullable so a "weight
 * only" log still works when the scale doesn't read impedance.
 *
 * Storage in kg for weight (single unit avoids mixed-unit aggregates);
 * the form lets the user type in either unit and converts at save time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('body_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('measured_at');

            // All three metrics are nullable — scale edge cases (bare
            // feet, low hydration) drop fat/muscle but still report
            // weight; accept the partial row.
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('body_fat_pct', 5, 2)->nullable();
            $table->decimal('muscle_pct', 5, 2)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['household_id', 'measured_at']);
            $table->index(['user_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('body_measurements');
    }
};

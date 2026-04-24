<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ongoing preventive-care log — heartworm, flea/tick, dewormer, dental,
 * nail trims, grooming. Each row records one application event with an
 * optional interval so the next_due_on can propagate. Latest row per
 * (pet, kind) acts as the active reminder — no separate schedule table.
 *
 * Distinct from pet_vaccinations (which tracks shot series + booster
 * schedules the vet tracks) and pet_checkups (which logs vet visits).
 * Heartworm pills + flea meds are monthly consumer-side — this is the
 * place for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_preventive_care', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();

            // Free string so new kinds land without migrations. Seed
            // menu: heartworm | flea_tick | dewormer | dental | nail_trim
            //       | grooming | ear_clean | other.
            $table->string('kind', 32);

            // Product/brand label (Heartgard, Bravecto, etc.) so the log
            // carries what the user actually used, not just the category.
            $table->string('label')->nullable();

            $table->date('applied_on');

            // Cadence. Null = one-off. When set, next_due_on is typically
            // applied_on + interval_days. The form derives it on save so
            // listing + radar queries don't need to recompute.
            $table->unsignedSmallInteger('interval_days')->nullable();
            $table->date('next_due_on')->nullable();

            $table->decimal('cost', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->foreignId('provider_id')->nullable()->constrained('health_providers')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['pet_id', 'kind', 'applied_on']);
            $table->index(['household_id', 'next_due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_preventive_care');
    }
};

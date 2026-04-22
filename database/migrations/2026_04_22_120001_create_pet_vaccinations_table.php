<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_vaccinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            // Vaccine slug from PetVaccineTemplates::all() — keeps rows
            // comparable across pets without hard-coding an enum column.
            $table->string('vaccine_name');
            // Rows with null administered_on are template placeholders
            // seeded at pet creation; the user fills them in as records
            // come back from the vet.
            $table->date('administered_on')->nullable();
            // valid_until drives the attention-radar query; null means
            // "no fixed expiry" (some boosters are single-shot for life).
            $table->date('valid_until')->nullable();
            // Separate from valid_until because some protocols schedule
            // a booster before the previous dose's validity runs out.
            $table->date('booster_due_on')->nullable();
            $table->foreignId('provider_id')->nullable()->constrained('health_providers')->nullOnDelete();
            $table->foreignId('proof_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'valid_until']);
            $table->index(['pet_id', 'vaccine_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_vaccinations');
    }
};

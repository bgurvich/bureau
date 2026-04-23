<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * City/county animal licenses. Distinct from vaccinations (a license is
 * a government registration bound to a municipality; a vaccine is a
 * medical event bound to a provider). Most jurisdictions renew yearly
 * so expires_on drives the Attention radar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();

            // Issuing jurisdiction — "Alameda County, CA", "NYC DOHMH",
            // "UK microchip DB". Free string so international + municipal
            // forms fit without an enum.
            $table->string('authority');

            // License / tag / microchip number printed on the card.
            $table->string('license_number')->nullable();

            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();

            $table->decimal('fee', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->foreignId('proof_media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['household_id', 'expires_on']);
            $table->index(['pet_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_licenses');
    }
};

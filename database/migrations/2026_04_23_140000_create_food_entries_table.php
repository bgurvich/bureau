<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Food intake log — one row per meal, snack, or drink. The table is
 * deliberately flat for the manual-entry v1: no foods catalogue, no
 * per-serving reference, no barcode linkage yet. A future OCR flow
 * will populate more of these columns automatically when a local LLM
 * identifies plates; for now the UI is the whole user interface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // eaten_at is a datetime (not just a date) so the day-grouped
            // list can render "breakfast / lunch / dinner" ordering off
            // the timestamp alone without needing a separate slot enum.
            $table->dateTime('eaten_at');

            // meal | snack | drink | other — the coarse bucket the user
            // picks from a curated enum, but stored free-string.
            $table->string('kind', 16)->default('meal');

            $table->string('label');

            // Servings × (calories + macros) = totals; we store the
            // totals directly for simplicity (the user keys them in from
            // a nutrition label). servings is informational only.
            $table->decimal('servings', 6, 2)->nullable();

            $table->unsignedSmallInteger('calories')->nullable();
            $table->decimal('protein_g', 6, 1)->nullable();
            $table->decimal('carbs_g', 6, 1)->nullable();
            $table->decimal('fat_g', 6, 1)->nullable();

            // Provenance for the row — stays 'manual' until OCR lands.
            $table->string('source', 16)->default('manual');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['household_id', 'eaten_at']);
            $table->index(['user_id', 'eaten_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_entries');
    }
};

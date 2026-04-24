<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop two tables that never became part of any feature:
 *
 * - fx_rates: seeded/migrated early as scaffolding for a currency
 *   conversion story that didn't ship. No writer, no reader, no test.
 * - events_stream: experimental event-bus table, never integrated.
 *
 * Both models are being removed alongside this migration. Keeping
 * down() functional in case we ever want to reintroduce the shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('fx_rates');
        Schema::dropIfExists('events_stream');
    }

    public function down(): void
    {
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->string('base', 3);
            $table->string('quote', 3);
            $table->date('as_of');
            $table->decimal('rate', 18, 8);
            $table->timestamps();
            $table->unique(['base', 'quote', 'as_of']);
        });

        Schema::create('events_stream', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index('occurred_at');
        });
    }
};

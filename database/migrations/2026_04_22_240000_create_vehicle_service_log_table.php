<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-vehicle maintenance history — oil changes, tire rotations, brake
 * jobs, inspections, repairs. Each row stands on its own; recurring
 * "service every 5k mi / 6 months" schedules live on RecurringRule
 * with subject_type=Vehicle, not here (keeps the "what happened"
 * history separate from the "what's next" projection).
 *
 * Odometer + odometer_unit denormalise per-entry so sorting by mileage
 * stays cheap even across vehicle odometer-unit swaps ("sold the
 * kilometer bike, kept the miles truck").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_service_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            $table->date('service_date');

            // Service kind — oil change, tire rotation, brakes, inspection,
            // battery, etc. Free-form string; picker menu in the inspector.
            $table->string('kind', 48);

            // Optional finer-grained label ("summer tires on / winter off",
            // "front brakes only"). The kind captures the category; the
            // label captures the specific job the user wants to remember.
            $table->string('label')->nullable();

            // Mileage at service time. Nullable because bicycle/boat/RV
            // services may not track it.
            $table->unsignedInteger('odometer')->nullable();
            $table->string('odometer_unit', 4)->nullable();

            $table->decimal('cost', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();

            // Shop/mechanic/dealership. Nullable — user may log a DIY
            // service with no counterparty.
            $table->foreignId('provider_contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['vehicle_id', 'service_date']);
            $table->index(['vehicle_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_service_log');
    }
};

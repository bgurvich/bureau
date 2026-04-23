<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Utility meter readings — water, electric, gas, etc. Attached to a
 * Property so multi-property households can keep separate consumption
 * trails. `kind` is a free-form string so regional utilities (sewage,
 * internet-data caps, propane) fit without schema churn; the inspector
 * form surfaces a curated picker but writes the raw slug.
 *
 * value + unit are intentionally independent — consumption charts and
 * the inline "delta from previous reading" both read the raw value and
 * surface the unit for display only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();

            // Utility kind — water | electric | gas | sewage | propane |
            // internet_data | other. Picker menu in the inspector.
            $table->string('kind', 32);

            $table->date('read_on');

            // Meter reading itself. Decimal because kWh/gallons arrive
            // with a fractional digit on most bills; 14,4 handles
            // "large industrial user" precision without overflow.
            $table->decimal('value', 14, 4);

            // Unit as free text so the form picker can vary by kind
            // (kwh / gal / mcf / therm / m3 / mb / gb) without an
            // enum table.
            $table->string('unit', 16);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['property_id', 'kind', 'read_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_readings');
    }
};

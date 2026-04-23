<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a service log carry its own "next due" pair (date and/or
 * odometer). The latest log per (vehicle, kind) acts as the active
 * reminder; the Attention radar surfaces services whose date has
 * arrived or passed.
 *
 * Miles-based trigger is informational — without a live odometer
 * feed, we can only compare against the most-recently-logged mileage.
 * The UI stamps it but the radar gates only on the date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_service_log', function (Blueprint $table) {
            $table->date('next_due_on')->nullable()->after('service_date');
            $table->unsignedInteger('next_due_odometer')->nullable()->after('next_due_on');

            $table->index(['vehicle_id', 'kind', 'next_due_on']);
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_service_log', function (Blueprint $table) {
            $table->dropIndex(['vehicle_id', 'kind', 'next_due_on']);
            $table->dropColumn(['next_due_on', 'next_due_odometer']);
        });
    }
};

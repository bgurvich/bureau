<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_checkups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            // Kind lets one table cover the cadence-driven visits the user
            // actually tracks: annual_checkup, dental_cleaning, teeth_cleaning,
            // grooming, blood_panel. Free-form string so new kinds don't need
            // a migration — the alerts radar queries everything with next_due_on.
            $table->string('kind')->default('annual_checkup');
            $table->date('checkup_on')->nullable();
            $table->date('next_due_on')->nullable();
            $table->foreignId('provider_id')->nullable()->constrained('health_providers')->nullOnDelete();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('findings')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'next_due_on']);
            $table->index(['pet_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_checkups');
    }
};

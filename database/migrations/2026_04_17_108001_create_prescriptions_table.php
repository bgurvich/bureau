<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('subject'); // User or Pet (when pets land)
            $table->foreignId('prescriber_id')->nullable()->constrained('health_providers')->nullOnDelete();
            $table->string('name');
            $table->string('dosage')->nullable();
            $table->string('schedule')->nullable(); // free text or rrule
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->unsignedSmallInteger('refills_left')->nullable();
            $table->date('next_refill_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'next_refill_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('specialty')->nullable(); // primary_care|dentist|optometrist|vet|...
            $table->string('name');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'specialty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_providers');
    }
};

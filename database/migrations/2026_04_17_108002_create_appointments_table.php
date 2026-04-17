<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('health_providers')->nullOnDelete();
            $table->nullableMorphs('subject'); // User or Pet
            $table->string('purpose')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('location')->nullable();
            $table->string('state')->default('scheduled'); // scheduled|completed|cancelled|no_show
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};

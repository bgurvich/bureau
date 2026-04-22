<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('species'); // dog|cat|rabbit|ferret|other
            $table->string('name');
            $table->string('breed')->nullable();
            $table->string('color')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('sex')->nullable(); // male|female|unknown
            $table->string('microchip_id')->nullable();
            $table->foreignId('primary_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('vet_provider_id')->nullable()->constrained('health_providers')->nullOnDelete();
            $table->foreignId('photo_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'species']);
            $table->index(['household_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pets');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('person'); // person|org
            $table->string('display_name');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('organization')->nullable();
            $table->json('phones')->nullable();
            $table->json('emails')->nullable();
            $table->json('addresses')->nullable();
            $table->foreignId('photo_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('favorite')->default(false);
            $table->timestamps();
            $table->index(['household_id', 'display_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};

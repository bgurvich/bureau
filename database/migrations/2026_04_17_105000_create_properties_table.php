<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // home|rental|land|vacation|storage|other
            $table->string('name');
            $table->json('address')->nullable();
            $table->date('acquired_on')->nullable();
            $table->date('disposed_on')->nullable();
            $table->decimal('purchase_price', 18, 4)->nullable();
            $table->string('purchase_currency', 3)->nullable();
            $table->decimal('size_value', 12, 2)->nullable();
            $table->string('size_unit')->nullable(); // sqft|sqm|acres
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};

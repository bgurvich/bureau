<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->nullable(); // appliance|electronic|furniture|art|jewelry|tool|clothing|other
            $table->foreignId('location_property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->string('room')->nullable();
            $table->foreignId('purchased_from_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->date('purchased_on')->nullable();
            $table->decimal('cost_amount', 18, 4)->nullable();
            $table->string('cost_currency', 3)->nullable();
            $table->string('brand')->nullable();
            $table->string('model_number')->nullable();
            $table->string('serial_number')->nullable();
            $table->date('warranty_expires_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'category']);
            $table->index(['household_id', 'warranty_expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};

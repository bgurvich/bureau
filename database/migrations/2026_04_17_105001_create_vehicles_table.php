<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // car|motorcycle|bicycle|boat|rv|other
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color')->nullable();
            $table->string('vin')->nullable();
            $table->string('license_plate')->nullable();
            $table->string('license_jurisdiction')->nullable();
            $table->date('acquired_on')->nullable();
            $table->date('disposed_on')->nullable();
            $table->decimal('purchase_price', 18, 4)->nullable();
            $table->string('purchase_currency', 3)->nullable();
            $table->foreignId('primary_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('odometer')->nullable();
            $table->string('odometer_unit')->default('mi'); // mi|km
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};

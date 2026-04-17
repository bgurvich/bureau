<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('coverage_kind'); // auto|home|health|life|disability|umbrella|travel|pet|renters|other
            $table->string('policy_number')->nullable();
            $table->foreignId('carrier_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('broker_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('beneficiary_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->decimal('premium_amount', 18, 4)->nullable();
            $table->string('premium_currency', 3)->nullable();
            $table->string('premium_cadence')->nullable(); // monthly|quarterly|annually
            $table->decimal('coverage_amount', 18, 4)->nullable();
            $table->string('coverage_currency', 3)->nullable();
            $table->decimal('deductible_amount', 18, 4)->nullable();
            $table->string('deductible_currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique('contract_id');
            $table->index('coverage_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_policies');
    }
};

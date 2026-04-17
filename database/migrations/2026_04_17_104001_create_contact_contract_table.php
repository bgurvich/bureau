<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_contract', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('party_role')->default('counterparty'); // counterparty|self|witness|agent|guarantor
            $table->timestamps();
            $table->unique(['contact_id', 'contract_id', 'party_role'], 'contact_contract_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_contract');
    }
};

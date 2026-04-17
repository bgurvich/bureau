<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // bank|credit|cash|investment|loan|mortgage
            $table->string('name');
            $table->string('institution')->nullable();
            $table->string('account_number_mask')->nullable();
            $table->string('currency', 3);
            $table->decimal('opening_balance', 18, 4)->default(0);
            $table->foreignId('counterparty_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->date('opened_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('include_in_net_worth')->default(true);
            $table->json('data')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};

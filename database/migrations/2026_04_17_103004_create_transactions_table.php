<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('counterparty_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->date('occurred_on');
            $table->decimal('amount', 18, 4); // signed: negative = outflow
            $table->string('currency', 3);
            $table->string('description')->nullable();
            $table->string('memo')->nullable();
            $table->string('status')->default('cleared'); // pending|cleared|reconciled
            $table->string('external_id')->nullable(); // dedupe key for imports
            $table->string('import_source')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'occurred_on']);
            $table->index(['account_id', 'occurred_on']);
            $table->index(['household_id', 'category_id', 'occurred_on']);
            $table->unique(['account_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

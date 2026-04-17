<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_projections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_id')->constrained('recurring_rules')->cascadeOnDelete();
            $table->date('due_on');
            $table->decimal('amount', 18, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status')->default('projected'); // projected|matched|paid|skipped|overdue
            $table->foreignId('matched_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('matched_transfer_id')->nullable()->constrained('transfers')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['rule_id', 'due_on']);
            $table->index(['household_id', 'due_on', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_projections');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quarterly estimated-tax rows under a tax year. Q1/Q2/Q3/Q4 for US
 * filers is ~April/June/September/January-next-year; we don't hard-
 * code the dates so state / non-US filers with different quarters
 * still fit. Account FK optional — pre-scheduled payments won't have
 * one until the actual transfer happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_estimated_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_year_id')->constrained('tax_years')->cascadeOnDelete();

            $table->string('quarter', 4); // Q1 | Q2 | Q3 | Q4
            $table->date('due_on');
            $table->date('paid_on')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['tax_year_id', 'quarter']);
            $table->index(['tax_year_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_estimated_payments');
    }
};

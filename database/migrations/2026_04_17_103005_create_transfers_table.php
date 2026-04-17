<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->date('occurred_on');
            $table->foreignId('from_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->decimal('from_amount', 18, 4);
            $table->string('from_currency', 3);
            $table->foreignId('to_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->decimal('to_amount', 18, 4);
            $table->string('to_currency', 3);
            $table->decimal('fee_amount', 18, 4)->nullable();
            $table->string('fee_currency', 3)->nullable();
            $table->string('description')->nullable();
            $table->string('status')->default('cleared');
            $table->string('external_id')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'occurred_on']);
            $table->index(['from_account_id', 'occurred_on']);
            $table->index(['to_account_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};

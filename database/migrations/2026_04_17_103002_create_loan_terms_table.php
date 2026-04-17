<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('direction'); // lent|borrowed
            $table->decimal('principal', 18, 4);
            $table->string('principal_currency', 3);
            $table->decimal('interest_rate', 8, 5)->nullable(); // annual percentage
            $table->string('rate_type')->default('fixed'); // fixed|variable|none
            $table->string('compound_period')->nullable(); // daily|monthly|annually|none
            $table->date('originated_on')->nullable();
            $table->date('matures_on')->nullable();
            $table->decimal('monthly_payment_amount', 18, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_terms');
    }
};

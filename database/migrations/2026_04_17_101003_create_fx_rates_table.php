<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('base', 3);
            $table->string('quote', 3);
            $table->date('as_of');
            $table->decimal('rate', 18, 8);
            $table->string('source')->nullable();
            $table->timestamps();
            $table->unique(['household_id', 'base', 'quote', 'as_of'], 'fx_rates_unique');
            $table->index(['base', 'quote', 'as_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};

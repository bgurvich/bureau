<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->morphs('valuable'); // Property|Vehicle|InventoryItem|Account(investment)
            $table->date('as_of');
            $table->decimal('value', 18, 4);
            $table->string('currency', 3);
            $table->string('method')->default('estimate'); // estimate|appraisal|market|cost|comparable
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'as_of']);
            $table->unique(['valuable_type', 'valuable_id', 'as_of'], 'asset_valuations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_valuations');
    }
};

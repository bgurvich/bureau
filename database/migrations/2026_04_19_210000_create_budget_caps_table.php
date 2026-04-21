<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_caps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->decimal('monthly_cap', 14, 4);
            $table->string('currency', 3)->default('USD');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['household_id', 'category_id'], 'budget_caps_household_category_unique');
            $table->index(['household_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_caps');
    }
};

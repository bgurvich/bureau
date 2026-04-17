<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // expense|income|transfer
            $table->string('name');
            $table->string('slug');
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('color', 7)->nullable();
            $table->string('icon')->nullable();
            $table->timestamps();
            $table->unique(['household_id', 'kind', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};

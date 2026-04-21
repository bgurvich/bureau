<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tag_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('pattern_type', 16)->default('contains');
            $table->string('pattern');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['household_id', 'active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_rules');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->longText('body');
            $table->boolean('pinned')->default(false);
            $table->boolean('private')->default(false);
            $table->timestamps();
            $table->index(['household_id', 'pinned', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};

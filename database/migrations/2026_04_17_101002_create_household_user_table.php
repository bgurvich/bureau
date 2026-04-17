<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member'); // owner|member|viewer
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();
            $table->unique(['household_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_user');
    }
};

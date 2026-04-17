<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('path');
            $table->timestamp('last_scanned_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['household_id', 'path'], 'media_folders_path_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_folders');
    }
};

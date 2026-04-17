<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('media_folders')->nullOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 127)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('hash', 64)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->string('ocr_status')->nullable(); // pending|done|failed|skip
            $table->longText('ocr_text')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'hash']);
            $table->index(['household_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};

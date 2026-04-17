<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('mail_messages')->cascadeOnDelete();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('filename')->nullable();
            $table->string('mime', 127)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_attachments');
    }
};

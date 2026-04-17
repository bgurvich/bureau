<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained('integrations')->nullOnDelete();
            $table->foreignId('inbox_id')->nullable()->constrained('mail_ingest_inbox')->nullOnDelete();
            $table->string('provider_message_id')->nullable();
            $table->timestamp('received_at');
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->json('to_addresses')->nullable();
            $table->string('subject')->nullable();
            $table->longText('text_body')->nullable();
            $table->longText('html_body')->nullable();
            $table->string('body_hash', 64)->nullable();
            $table->json('headers')->nullable();
            $table->string('classification')->nullable(); // receipt|bill|statement|contract|notice|unknown
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'received_at']);
            $table->index(['household_id', 'classification']);
            $table->unique(['integration_id', 'provider_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_messages');
    }
};

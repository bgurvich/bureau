<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained('integrations')->nullOnDelete();
            $table->string('name');
            $table->string('url')->nullable(); // ICS URL for read-only subscriptions
            $table->string('color', 7)->nullable();
            $table->boolean('active')->default(true);
            $table->string('last_etag')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
    }
};

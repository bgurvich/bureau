<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_feed_id')->nullable()->constrained('calendar_feeds')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('location')->nullable();
            $table->string('url')->nullable();
            $table->text('notes')->nullable();
            $table->string('external_uid')->nullable(); // for ICS/CalDAV sync dedupe
            $table->string('external_source')->nullable();
            $table->string('status')->default('confirmed'); // confirmed|tentative|cancelled
            $table->timestamps();
            $table->index(['household_id', 'starts_at']);
            $table->unique(['calendar_feed_id', 'external_uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};

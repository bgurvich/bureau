<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('provider');   // gmail|fastmail|google_cal|caldav|postmark|slack|twilio|telegram|plaid
            $table->string('kind');        // mail|calendar|bank|notification
            $table->string('label')->nullable();
            $table->text('credentials')->nullable(); // encrypted json
            $table->string('status')->default('active'); // active|paused|error|revoked
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'provider', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};

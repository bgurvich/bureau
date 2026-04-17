<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind'); // e.g. bill_due|doc_expiring|task_due|anomaly|weekly_review
            $table->string('channel'); // in_app|email|slack|sms|telegram|push
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('throttle_minutes')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'household_id', 'kind', 'channel'], 'unp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
    }
};

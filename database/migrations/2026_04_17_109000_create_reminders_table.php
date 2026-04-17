<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->nullableMorphs('remindable'); // anything with a canonical date
            $table->string('title');
            $table->text('body')->nullable();
            $table->dateTime('remind_at');
            $table->string('channel')->default('in_app'); // in_app|email|slack|sms|telegram|push
            $table->timestamp('fired_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('state')->default('pending'); // pending|fired|acknowledged|cancelled
            $table->timestamps();
            $table->index(['household_id', 'remind_at', 'state']);
            $table->index(['user_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};

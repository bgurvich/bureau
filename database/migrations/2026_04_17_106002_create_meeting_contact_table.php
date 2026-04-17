<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_contact', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('role')->default('attendee'); // organizer|attendee|optional
            $table->string('rsvp')->nullable(); // accepted|declined|tentative|no-response
            $table->timestamps();
            $table->unique(['meeting_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_contact');
    }
};

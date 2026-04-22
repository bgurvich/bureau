<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily journal entries — a private running log tied to a date. Distinct
 * from Note (Notes are topical and ad-hoc; Journal is chronological and
 * typically one-per-day). Mood/weather/location are all nullable because
 * the user's workflow ranges from "one-line mood" to "full paragraphs".
 * Subjects hang off journal_entry_subjects so an entry can reference the
 * pets/contacts/properties it was about, and aggregate views on those
 * records can surface the journal threads that mention them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->date('occurred_on');
            $table->string('title')->nullable();
            $table->longText('body');

            // Short mood token (e.g. 'good'/'low'/'excited'/'anxious').
            // Free string — a picker in the form offers common values
            // but we don't want to lock the taxonomy at the schema layer.
            $table->string('mood', 32)->nullable();

            // Ambient context captured in prose, not for heavy querying.
            $table->string('weather', 64)->nullable();
            $table->string('location')->nullable();

            $table->boolean('private')->default(true);

            $table->timestamps();

            $table->index(['household_id', 'occurred_on']);
            $table->index(['user_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};

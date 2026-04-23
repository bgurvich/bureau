<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal media log — books, films, podcasts, shows, articles, games.
 * Tracks what's on the wishlist, what's in progress, what's done, and
 * the user's take on each. Distinct from `media` (that's for uploaded
 * files like receipts + photos); this is a reading/watching/listening
 * LIST the user curates by hand.
 *
 * Status lifecycle: wishlist → in_progress → done (or dropped). The
 * started_on / finished_on dates are the commitment/completion marks;
 * rating (1–5) is free-floating — a work can be rated while still
 * in_progress ("this is already a 5").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // book | film | show | podcast | article | game | other
            $table->string('kind', 16);

            $table->string('title');

            // Author for books, director for films, host for podcasts,
            // studio for games — one free string, no per-kind schema.
            $table->string('creator')->nullable();

            // wishlist | in_progress | done | dropped | paused
            $table->string('status', 16)->default('wishlist');

            $table->date('started_on')->nullable();
            $table->date('finished_on')->nullable();

            // 1–5 personal rating. Null until the user has an opinion.
            $table->unsignedTinyInteger('rating')->nullable();

            $table->string('external_url', 500)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['household_id', 'kind', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_log_entries');
    }
};

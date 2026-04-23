<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decision log — one row per explicit choice the user wants to
 * revisit. Motivated by the ADR pattern: capture the context, the
 * alternatives you weighed, what you picked, and why, so future-you
 * (or a partner) can audit the reasoning without asking.
 *
 * outcome starts null and gets filled in after the decision has
 * played out; follow_up_on drives the Attention radar so the log
 * pings you when it's time to record whether the call was right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->date('decided_on');
            $table->string('title');

            // What prompted the decision — the situation, constraints,
            // pressure. Free text; one paragraph is plenty.
            $table->text('context')->nullable();

            // Alternatives you considered, one per line typically.
            // Renders as a bulleted list in the detail view.
            $table->text('options_considered')->nullable();

            // The actual pick.
            $table->string('chosen')->nullable();

            // Why this pick won — the rationale line future-you reads
            // first when revisiting the call.
            $table->text('rationale')->nullable();

            // When to come back and record the outcome. Null = no
            // scheduled follow-up (one-way-door decisions where the
            // outcome is self-evident).
            $table->date('follow_up_on')->nullable();

            // How it turned out, filled in retrospectively. Null until
            // the follow-up pass. A concise "what actually happened".
            $table->text('outcome')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['household_id', 'decided_on']);
            $table->index(['household_id', 'follow_up_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions');
    }
};

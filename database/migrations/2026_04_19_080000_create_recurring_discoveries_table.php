<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_discoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('counterparty_contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            // Normalized lowercase-no-numerics token used to group sibling
            // transactions (e.g. "netflix" from "NETFLIX.COM 12345").
            $table->string('description_fingerprint');

            // Classified recurrence: weekly|biweekly|monthly|quarterly|yearly.
            $table->string('cadence', 16);

            $table->decimal('median_amount', 18, 4);
            $table->decimal('amount_variance', 18, 4)->default(0);
            $table->unsignedSmallInteger('occurrence_count');
            $table->date('first_seen_on');
            $table->date('last_seen_on');
            $table->decimal('score', 9, 4)->default(0);

            $table->string('status', 16)->default('pending'); // pending|accepted|dismissed

            // Uniqueness guard for repeat-safe rediscovery. Combines account +
            // counterparty (nullable → 0 token) + description_fingerprint +
            // cadence. Dismissals/acceptances persist across reruns.
            $table->string('signature_hash', 64);

            $table->timestamps();

            $table->unique(['household_id', 'signature_hash']);
            $table->index(['household_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_discoveries');
    }
};

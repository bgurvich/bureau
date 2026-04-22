<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookkeeper portal grants — time-boxed read-only access to a single
 * household's fiscal data by an external party (CPA, bookkeeper). The
 * raw token is shown to the household owner ONCE at grant creation and
 * stored hashed; the owner shares the one-time URL out-of-band
 * (email, Signal, whatever). The portal session is guest-style
 * (no User row) and scoped via `CurrentHousehold` by middleware.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            // Who is this grant for — display only, not used for auth.
            $table->string('grantee_email')->nullable();
            // Human-readable tag the owner sets ("2025 Tax Year — Smith CPA").
            $table->string('label')->nullable();
            // SHA-256 of the raw token. Stored hashed so a DB leak doesn't
            // hand out active portal URLs.
            $table->string('token_hash')->unique();
            // Last 6 chars of the raw token — displayed in the grants
            // list so the owner can correlate a URL back to a grant.
            $table->string('token_tail', 12);
            // Future-proofing: scope of modules the grant can see.
            // For v1 we only surface 'fiscal'.
            $table->json('scope')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_grants');
    }
};

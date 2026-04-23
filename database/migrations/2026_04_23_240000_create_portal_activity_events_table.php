<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for bookkeeper-portal sessions. Every token consumption,
 * page view, CSV export, and sign-out lands a row here so the owner
 * can see what the external bookkeeper saw and downloaded.
 *
 * portal_grant_id is nullable-on-delete so the event survives grant
 * deletion (record-keeping for revoked grants is the whole point).
 * household_id redundantly on the event lets the owner-side query
 * scope efficiently without joining through the grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_grant_id')->nullable()->constrained('portal_grants')->nullOnDelete();

            // consumed_token | page_view | export_csv | signed_out.
            // Free string so new event kinds can land without migrations.
            $table->string('action', 32);

            // Structured blob: route_name, query_params, record_counts,
            // ip, user_agent fragment, download filename, etc.
            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['household_id', 'created_at']);
            $table->index(['portal_grant_id', 'created_at']);
            $table->index(['household_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_activity_events');
    }
};

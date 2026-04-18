<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bills loop schema — distinguish issue date from due date, track auto-pay,
 * and preserve payment-match audit history on projections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('due_offset_days')->default(0)->after('lead_days');
            $table->boolean('autopay')->default(false)->after('due_offset_days');
        });

        Schema::table('recurring_projections', function (Blueprint $table) {
            $table->date('issued_on')->nullable()->after('due_on');
            $table->boolean('autopay')->default(false)->after('issued_on');
            $table->timestamp('matched_at')->nullable()->after('matched_transfer_id');
            $table->timestamp('unmatched_at')->nullable()->after('matched_at');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_rules', function (Blueprint $table) {
            $table->dropColumn(['due_offset_days', 'autopay']);
        });

        Schema::table('recurring_projections', function (Blueprint $table) {
            $table->dropColumn(['issued_on', 'autopay', 'matched_at', 'unmatched_at']);
        });
    }
};

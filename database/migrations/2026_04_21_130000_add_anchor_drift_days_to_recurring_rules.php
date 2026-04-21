<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signed day offset applied on top of every RRULE-emitted anchor when
 * expanding projections. Learned from the rule's recent match history
 * by ProjectionDriftDetector — if the last N matched transactions
 * consistently landed K days after (or before) their projection's
 * due_on, the rule's effective anchor shifts by K so future
 * projections are aimed where reality already is. Reversible: set
 * back to null to disable; also editable from the bill inspector.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_rules', function (Blueprint $table) {
            $table->tinyInteger('anchor_drift_days')->nullable()->after('due_offset_days');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_rules', function (Blueprint $table) {
            $table->dropColumn('anchor_drift_days');
        });
    }
};

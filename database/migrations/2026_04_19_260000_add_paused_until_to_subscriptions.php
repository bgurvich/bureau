<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pausing a subscription with an auto-resume date — common case is "trial
 * month, resume at full price on :date". A nightly cron flips state back
 * to active once paused_until <= today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->date('paused_until')->nullable()->after('state');
            $table->index('paused_until');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['paused_until']);
            $table->dropColumn('paused_until');
        });
    }
};

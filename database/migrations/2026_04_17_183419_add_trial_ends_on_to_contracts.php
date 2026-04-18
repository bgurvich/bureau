<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trial is a subscription with an early cancel-by date. Storing it on
 * contracts keeps trial and paid phases on the same row and lets the
 * attention radar surface trial_ends_on the same way it treats expirations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->date('trial_ends_on')->nullable()->after('ends_on');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('trial_ends_on');
        });
    }
};

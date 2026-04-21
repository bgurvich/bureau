<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WF checking statements have a "Check Number" column between Date and
 * Description. Most rows leave it empty, but paper checks land there as
 * numeric strings and ACH rows occasionally carry a legend marker
 * (e.g. "<" for "Business to Business ACH"). Preserve whatever the
 * parser extracted so the original document is reconstructable from
 * ledger data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('check_number', 32)->nullable()->after('reference_number');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('check_number');
        });
    }
};

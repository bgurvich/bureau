<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two roles instead of one. "Preparer" covers CPA / accountant / spouse
 * / self / friend — whoever puts the numbers on the return. "Bookkeeper"
 * is the ongoing categorization partner (often a separate person from
 * the preparer). If handled_by_contact_id was set on any existing row,
 * preserve it as preparer_contact_id so no data is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_years', function (Blueprint $table) {
            $table->foreignId('preparer_contact_id')
                ->nullable()
                ->after('filing_status')
                ->constrained('contacts')
                ->nullOnDelete();
            $table->foreignId('bookkeeper_contact_id')
                ->nullable()
                ->after('preparer_contact_id')
                ->constrained('contacts')
                ->nullOnDelete();
        });

        // Carry forward the brief life of handled_by_contact_id.
        if (Schema::hasColumn('tax_years', 'handled_by_contact_id')) {
            DB::table('tax_years')
                ->whereNotNull('handled_by_contact_id')
                ->update(['preparer_contact_id' => DB::raw('handled_by_contact_id')]);

            Schema::table('tax_years', function (Blueprint $table) {
                $table->dropForeign(['handled_by_contact_id']);
                $table->dropColumn('handled_by_contact_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('tax_years', function (Blueprint $table) {
            $table->foreignId('handled_by_contact_id')
                ->nullable()
                ->after('filing_status')
                ->constrained('contacts')
                ->nullOnDelete();
        });

        DB::table('tax_years')
            ->whereNotNull('preparer_contact_id')
            ->update(['handled_by_contact_id' => DB::raw('preparer_contact_id')]);

        Schema::table('tax_years', function (Blueprint $table) {
            $table->dropForeign(['preparer_contact_id']);
            $table->dropColumn('preparer_contact_id');
            $table->dropForeign(['bookkeeper_contact_id']);
            $table->dropColumn('bookkeeper_contact_id');
        });
    }
};

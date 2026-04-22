<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('reconciled_at')->nullable()->after('status');
            $table->index(['household_id', 'reconciled_at']);
        });

        // Backfill: treat every pre-existing row as already reconciled so the
        // queue starts empty on first deploy. Imports created after this
        // migration land with reconciled_at=null and surface in /reconcile.
        DB::table('transactions')->update(['reconciled_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'reconciled_at']);
            $table->dropColumn('reconciled_at');
        });
    }
};

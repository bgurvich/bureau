<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks an inventory row as "finished with data entry". Bulk capture creates
 * rows with processed_at=null (unprocessed); the Inspector stamps it on save.
 * The drill-down's status filter uses this to isolate rows that still need
 * detail in the walking-a-closet workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->timestamp('processed_at')->nullable()->after('updated_at');
        });

        // Existing rows predate bulk capture — treat them as processed so they
        // don't show up in the "unprocessed" filter after the migration runs.
        DB::table('inventory_items')->whereNull('processed_at')->update(['processed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('processed_at');
        });
    }
};

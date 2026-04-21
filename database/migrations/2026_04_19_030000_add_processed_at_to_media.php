<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // User-level "I've dealt with this scan" flag. Auto-set when the
            // scan's extraction is turned into a bill/transaction via the
            // Inspector, or manually via the dismiss button. Mirrors
            // inventory_items.processed_at semantics.
            $table->timestamp('processed_at')->nullable()->after('extraction_status');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('processed_at');
        });
    }
};

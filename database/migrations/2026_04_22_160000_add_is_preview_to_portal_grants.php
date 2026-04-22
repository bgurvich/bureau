<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_grants', function (Blueprint $table) {
            // Owner-created short-lived grants used to eyeball what the
            // portal looks like to a CPA. Filtered out of the grants list
            // so they don't clutter the real CPA roster.
            $table->boolean('is_preview')->default(false)->after('scope');
        });
    }

    public function down(): void
    {
        Schema::table('portal_grants', function (Blueprint $table) {
            $table->dropColumn('is_preview');
        });
    }
};

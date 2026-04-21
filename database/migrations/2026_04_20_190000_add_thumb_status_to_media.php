<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (! Schema::hasColumn('media', 'thumb_status')) {
                // pending → queued but not run yet
                // done    → thumb_path populated, served by /media/{id}/thumb
                // failed  → pdftoppm exited non-zero (missing binary, corrupt
                //           PDF, encrypted). Tile surfaces a regenerate button.
                // skip    → not applicable (e.g. non-PDF row)
                $table->string('thumb_status', 16)->default('pending')->after('thumb_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (Schema::hasColumn('media', 'thumb_status')) {
                $table->dropColumn('thumb_status');
            }
        });
    }
};

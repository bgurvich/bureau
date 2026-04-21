<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (! Schema::hasColumn('media', 'thumb_path')) {
                // Stored relative to the media disk so it follows the row if
                // the disk ever changes (e.g. local → S3). Nullable because
                // images don't need a generated thumb (they're their own) and
                // the background job may not have run yet for fresh PDFs.
                $table->string('thumb_path')->nullable()->after('path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (Schema::hasColumn('media', 'thumb_path')) {
                $table->dropColumn('thumb_path');
            }
        });
    }
};

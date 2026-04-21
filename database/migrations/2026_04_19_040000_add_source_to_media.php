<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // Provenance: how the Media row arrived in Bureau. Filterable on
            // the Inbox and /media. Values: upload|folder|mail|mobile|api.
            $table->string('source', 16)->default('upload')->after('disk');
            $table->index(['household_id', 'source']);
        });

        // Backfill existing rows: folder-rescanned scans get 'folder'; rows
        // referenced by mail_attachments get 'mail'; everything else keeps
        // the 'upload' default set above.
        DB::table('media')
            ->whereNotNull('folder_id')
            ->update(['source' => 'folder']);

        DB::table('media')
            ->whereIn('id', DB::table('mail_attachments')->whereNotNull('media_id')->pluck('media_id'))
            ->update(['source' => 'mail']);
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'source']);
            $table->dropColumn('source');
        });
    }
};

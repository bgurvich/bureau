<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            // RFC 5322 Message-ID header, used for cross-mailbox dedup when the
            // same email lands in multiple inboxes (e.g. forwarded to a second
            // gmail or auto-routed through Postmark). provider_message_id is a
            // separate concept (gmail historyId / JMAP blobId / Postmark id)
            // and stays as-is.
            $table->string('message_id')->nullable()->after('provider_message_id');
            $table->unique(['household_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropUnique(['household_id', 'message_id']);
            $table->dropColumn('message_id');
        });
    }
};

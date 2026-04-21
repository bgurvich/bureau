<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // How the user cancels this subscription/service: direct link to
            // the cancel page, and/or the unsubscribe email to contact.
            // Captured at contract creation so the deliberate kill switch is
            // one click away when the time comes.
            $table->string('cancellation_url')->nullable()->after('auto_renews');
            $table->string('cancellation_email')->nullable()->after('cancellation_url');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['cancellation_url', 'cancellation_email']);
        });
    }
};

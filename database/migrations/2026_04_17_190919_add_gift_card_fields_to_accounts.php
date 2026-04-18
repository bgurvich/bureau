<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gift cards are prepaid balances scoped to a single vendor and usually expire.
 * Modeling them as an Account type reuses balance math + transactions + net
 * worth; the two new columns carry the bits Accounts didn't cover: who issued
 * the card and when it expires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('vendor_contact_id')->nullable()->after('institution')->constrained('contacts')->nullOnDelete();
            $table->date('expires_on')->nullable()->after('vendor_contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_contact_id');
            $table->dropColumn('expires_on');
        });
    }
};

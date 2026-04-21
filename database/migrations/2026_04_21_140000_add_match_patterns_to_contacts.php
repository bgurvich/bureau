<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit vendor-match rules stored on the Contact itself. One regex
 * (or plain substring) per line — the import + re-resolver match
 * these against the raw transaction description and assign the
 * contact on the first hit. Without this, renaming an auto-created
 * contact would break matching because the resolver had no way to
 * tell that "Costco" was still the owner of fingerprint "purchase
 * authorized".
 *
 * Auto-created vendors get their fingerprint seeded here at creation
 * time so renames survive subsequent re-resolves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->text('match_patterns')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('match_patterns');
        });
    }
};

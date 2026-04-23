<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who prepared/filed this return — CPA, accountant, spouse, self.
 * Typed as a FK to contacts so the "my accountant called to ask X"
 * flow can jump straight from the tax year to the contact's phone/email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_years', function (Blueprint $table) {
            $table->foreignId('handled_by_contact_id')
                ->nullable()
                ->after('filing_status')
                ->constrained('contacts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tax_years', function (Blueprint $table) {
            $table->dropForeign(['handled_by_contact_id']);
            $table->dropColumn('handled_by_contact_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema additions for bookkeeper / CPA handoff:
 *   - external_code on accounts & categories (maps to the CPA's chart of accounts)
 *   - vendor/customer flags + tax_id on contacts
 *   - reference_number, tax_amount, tax_code on transactions
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('external_code', 32)->nullable()->after('name');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('external_code', 32)->nullable()->after('slug');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_vendor')->default(false)->after('favorite');
            $table->boolean('is_customer')->default(false)->after('is_vendor');
            $table->string('tax_id', 64)->nullable()->after('is_customer');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('reference_number', 64)->nullable()->after('description');
            $table->decimal('tax_amount', 18, 4)->nullable()->after('reference_number');
            $table->string('tax_code', 32)->nullable()->after('tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', fn (Blueprint $t) => $t->dropColumn('external_code'));
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('external_code'));
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn(['is_vendor', 'is_customer', 'tax_id']));
        Schema::table('transactions', fn (Blueprint $t) => $t->dropColumn(['reference_number', 'tax_amount', 'tax_code']));
    }
};

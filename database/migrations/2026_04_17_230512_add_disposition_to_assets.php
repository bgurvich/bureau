<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured disposition — distinguishes sold/gifted/traded/scrapped/stolen/etc
 * so an ended-ownership record carries the full story (not just a date). When
 * disposition=sold|traded, optional sale_amount + buyer_contact_id capture the
 * proceeds side — the Inspector can later prompt to auto-create a matching
 * income transaction.
 *
 * inventory_items gains disposed_on here too — properties + vehicles already
 * had it from their original migrations.
 *
 * Idempotent: checks column existence before adding, so partial prior state
 * doesn't block.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_items', 'disposed_on')) {
            Schema::table('inventory_items', function (Blueprint $t) {
                $t->date('disposed_on')->nullable()->after('warranty_expires_on');
            });
        }

        foreach (['properties', 'vehicles', 'inventory_items'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'disposition')) {
                    $t->string('disposition')->nullable()->after('disposed_on');
                }
                if (! Schema::hasColumn($table, 'sale_amount')) {
                    $t->decimal('sale_amount', 18, 4)->nullable()->after('disposition');
                }
                if (! Schema::hasColumn($table, 'sale_currency')) {
                    $t->string('sale_currency', 3)->nullable()->after('sale_amount');
                }
                if (! Schema::hasColumn($table, 'buyer_contact_id')) {
                    $t->foreignId('buyer_contact_id')->nullable()->after('sale_currency')->constrained('contacts')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['inventory_items', 'vehicles', 'properties'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('buyer_contact_id');
                $t->dropColumn(['disposition', 'sale_amount', 'sale_currency']);
            });
        }

        Schema::table('inventory_items', function (Blueprint $t) {
            $t->dropColumn('disposed_on');
        });
    }
};

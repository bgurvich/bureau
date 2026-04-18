<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase trail on inventory: order number (for returns / warranty claims) and
 * the last day the item can be returned. Vendor is already on the table via the
 * existing `purchased_from_contact_id` FK — we surface it in the UI as "Vendor".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('order_number')->nullable()->after('cost_currency');
            $table->date('return_by')->nullable()->after('order_number');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['order_number', 'return_by']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `quantity` lets one row hold "Candles × 20" instead of 20 rows. `container`
 * adds a third location tier below property + room (e.g. "closet 1", "travel
 * bag"). Free-text for now — a dedicated `inventory_containers` table can
 * come later if shared labels start mattering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->after('name');
            $table->string('container')->nullable()->after('room');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'container']);
        });
    }
};

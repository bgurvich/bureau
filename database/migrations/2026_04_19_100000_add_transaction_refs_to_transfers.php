<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            // When a Transfer is created by pairing two existing Transactions
            // (statement import, CSV, Plaid), these FKs point at the source
            // Transaction rows on each side. Keeps the audit trail and
            // prevents double-counting in net-worth (transactions whose id
            // appears as either of these are excluded from the money radar).
            $table->foreignId('from_transaction_id')->nullable()->after('from_currency')
                ->constrained('transactions')->nullOnDelete();
            $table->foreignId('to_transaction_id')->nullable()->after('to_currency')
                ->constrained('transactions')->nullOnDelete();
            $table->unique('from_transaction_id');
            $table->unique('to_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropUnique(['from_transaction_id']);
            $table->dropUnique(['to_transaction_id']);
            $table->dropConstrainedForeignId('from_transaction_id');
            $table->dropConstrainedForeignId('to_transaction_id');
        });
    }
};

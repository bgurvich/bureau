<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Links a child-transaction to its funding parent across accounts.
            // Primary use: PayPal purchases on the PayPal account, each
            // "funded by" a bank-account debit that moved money into PayPal.
            // The parent side is typically a transfer-style bank outflow;
            // children are the itemized merchant charges. Nullable — most
            // transactions stand alone and never get this set.
            $table->foreignId('funded_by_transaction_id')->nullable()->after('external_id')
                ->constrained('transactions')->nullOnDelete();
            $table->index('funded_by_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('funded_by_transaction_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Running balance the bank reported on the same row as this transaction.
// Optional — only populated for imports from statements whose layout carries
// an "Ending daily balance" column. Gives reconciliation a per-row truth
// oracle: computed_running_balance(up to this row) must equal the stored
// value, otherwise a missing or misamounted transaction lives between this
// row and the previous one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('closing_balance', 18, 4)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('closing_balance');
        });
    }
};

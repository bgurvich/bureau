<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Default spending/income category for transactions linked to
            // this contact. When set, TransactionObserver copies it onto
            // freshly-created transactions that lack an explicit category,
            // so recurring Costco / Netflix / utilities charges land in
            // the right bucket without manual categorisation every time.
            $table->foreignId('category_id')->nullable()->after('kind')
                ->constrained('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};

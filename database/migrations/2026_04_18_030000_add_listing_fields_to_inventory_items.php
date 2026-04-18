<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_items', 'is_for_sale')) {
                $table->boolean('is_for_sale')->default(false)->after('disposed_on');
            }
            if (! Schema::hasColumn('inventory_items', 'listing_asking_amount')) {
                $table->decimal('listing_asking_amount', 18, 4)->nullable()->after('is_for_sale');
            }
            if (! Schema::hasColumn('inventory_items', 'listing_asking_currency')) {
                $table->string('listing_asking_currency', 3)->nullable()->after('listing_asking_amount');
            }
            if (! Schema::hasColumn('inventory_items', 'listing_platform')) {
                $table->string('listing_platform')->nullable()->after('listing_asking_currency');
            }
            if (! Schema::hasColumn('inventory_items', 'listing_url')) {
                $table->string('listing_url', 512)->nullable()->after('listing_platform');
            }
            if (! Schema::hasColumn('inventory_items', 'listing_posted_at')) {
                $table->date('listing_posted_at')->nullable()->after('listing_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $columns = [
                'listing_posted_at',
                'listing_url',
                'listing_platform',
                'listing_asking_currency',
                'listing_asking_amount',
                'is_for_sale',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('inventory_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

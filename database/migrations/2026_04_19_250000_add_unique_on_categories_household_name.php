<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prevent duplicate categories within a household. The app already
 * deduplicated existing rows at deploy time; this guards against new ones
 * being introduced by seeders, imports, or concurrent creates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unique(['household_id', 'name'], 'categories_household_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_household_name_unique');
        });
    }
};

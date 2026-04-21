<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Earlier migration 2026_04_19_250000 added unique(household_id, name) to
 * prevent duplicate categories. But legitimate categories share names
 * across parents — "Insurance > Health" vs top-level "Health" — and the
 * starter seeder needs both. Slug is the real uniqueness key (it encodes
 * the parent path: e.g. `insurance/health` vs `health`). Swap the
 * constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_household_name_unique');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->unique(['household_id', 'slug'], 'categories_household_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_household_slug_unique');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->unique(['household_id', 'name'], 'categories_household_name_unique');
        });
    }
};

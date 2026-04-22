<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source-label → category hints stored on the Category itself, parallel
 * to `contacts.match_patterns`. Each line is a plain substring or regex
 * matched case-insensitively against a statement-source category label
 * (e.g. Costco's Category column) at import time. Keeps description-
 * based matching in `category_rules` untouched — this is a second,
 * orthogonal signal from whatever taxonomy the bank/issuer exports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->text('match_patterns')->nullable()->after('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('match_patterns');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Nullable DATE. Year is required by the column type; use
            // 1900-MM-DD as the sentinel when the user only knows the
            // month/day. The Birthdays helper never displays the 1900
            // year, so the sentinel is invisible downstream.
            $table->date('birthday')->nullable()->after('contact_roles');
            $table->index(['household_id', 'birthday']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'birthday']);
            $table->dropColumn('birthday');
        });
    }
};

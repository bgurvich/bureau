<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_accounts', function (Blueprint $table) {
            $table->string('kind')->default('other')->after('service_name');
            $table->index(['household_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('online_accounts', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};

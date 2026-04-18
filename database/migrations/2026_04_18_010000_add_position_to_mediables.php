<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mediables', function (Blueprint $table) {
            if (! Schema::hasColumn('mediables', 'position')) {
                $table->unsignedInteger('position')->default(0)->after('role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mediables', function (Blueprint $table) {
            if (Schema::hasColumn('mediables', 'position')) {
                $table->dropColumn('position');
            }
        });
    }
};

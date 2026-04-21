<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_subjects', function (Blueprint $table) {
            // Ordering within a task's subjects list — user-controlled via
            // up/down arrows. New rows get the next-highest value on insert.
            $table->unsignedSmallInteger('position')->default(0)->after('subject_id');
        });
        Schema::table('note_subjects', function (Blueprint $table) {
            $table->unsignedSmallInteger('position')->default(0)->after('subject_id');
        });
    }

    public function down(): void
    {
        Schema::table('task_subjects', function (Blueprint $table) {
            $table->dropColumn('position');
        });
        Schema::table('note_subjects', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};

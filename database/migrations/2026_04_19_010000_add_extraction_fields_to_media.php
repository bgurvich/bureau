<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->json('ocr_extracted')->nullable()->after('ocr_text');
            $table->string('extraction_status')->nullable()->after('ocr_extracted'); // pending|done|failed|skipped
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['ocr_extracted', 'extraction_status']);
        });
    }
};

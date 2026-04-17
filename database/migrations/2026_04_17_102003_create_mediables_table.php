<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mediables', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->morphs('mediable');
            $table->string('role')->nullable(); // e.g. "scan", "photo", "proof"
            $table->primary(['media_id', 'mediable_type', 'mediable_id'], 'mediables_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mediables');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_ingest_inbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('local_address')->unique(); // e.g. u-abc123@inbound.bureau.app
            $table->string('forward_target')->nullable(); // optional onward-forward
            $table->boolean('active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_ingest_inbox');
    }
};

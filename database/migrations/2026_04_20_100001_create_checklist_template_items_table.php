<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_template_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->integer('position')->default(0);
            // Soft-hide without losing historical ticks that reference this id.
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['checklist_template_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_template_items');
    }
};

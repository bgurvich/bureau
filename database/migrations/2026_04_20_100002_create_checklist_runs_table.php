<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_template_id')->constrained()->cascadeOnDelete();
            $table->date('run_date');
            $table->json('ticked_item_ids')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['checklist_template_id', 'run_date']);
            $table->index(['household_id', 'run_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_runs');
    }
};

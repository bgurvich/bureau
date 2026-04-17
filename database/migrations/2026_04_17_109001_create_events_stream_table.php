<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events_stream', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->morphs('source'); // Task, Meeting, Transaction, RecurringProjection, Contract, Document, Appointment, ...
            $table->dateTime('happened_at');
            $table->string('kind'); // task_due|meeting|transaction|bill_due|doc_expires|contract_renewal|appointment|...
            $table->string('summary');
            $table->json('payload')->nullable(); // domain-specific extras for rendering
            $table->timestamps();
            $table->index(['household_id', 'happened_at']);
            $table->index(['household_id', 'kind', 'happened_at']);
            $table->unique(['source_type', 'source_id', 'kind'], 'events_stream_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events_stream');
    }
};

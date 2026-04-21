<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_mail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->date('received_on');
            $table->string('kind', 32)->default('other'); // letter|bill|package_slip|ad|legal|medical|other
            $table->string('subject')->nullable();
            $table->text('summary')->nullable();
            $table->boolean('action_required')->default(false);
            // Soft "done" flag — mail that's been filed / acted on stays in
            // the table for history but drops off the "inbox" view.
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'received_on']);
            $table->index(['household_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_mail');
    }
};

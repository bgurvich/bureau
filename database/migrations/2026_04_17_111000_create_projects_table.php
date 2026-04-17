<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('color', 7)->nullable();
            $table->boolean('billable')->default(false);
            $table->decimal('hourly_rate', 12, 4)->nullable();
            $table->string('hourly_rate_currency', 3)->nullable();
            $table->foreignId('client_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();
            $table->unique(['household_id', 'slug']);
            $table->index(['household_id', 'archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // agreement|policy|insurance|subscription|employment|lease|loan|other
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('auto_renews')->default(false);
            $table->unsignedSmallInteger('renewal_notice_days')->nullable();
            $table->decimal('monthly_cost_amount', 18, 4)->nullable();
            $table->string('monthly_cost_currency', 3)->nullable();
            $table->string('state')->default('active'); // draft|active|expiring|ended|cancelled
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'state', 'ends_on']);
            $table->index(['household_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};

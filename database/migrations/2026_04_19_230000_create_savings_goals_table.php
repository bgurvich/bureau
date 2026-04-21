<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('target_amount', 14, 4);
            $table->date('target_date')->nullable();
            $table->decimal('starting_amount', 14, 4)->default(0);
            // Optional: tie the goal's current progress to an Account's balance
            // (e.g. a dedicated HYSA). When null, the user updates progress by
            // setting saved_amount manually.
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('saved_amount', 14, 4)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('state', 16)->default('active');   // active | paused | achieved | abandoned
            $table->timestamps();

            $table->index(['household_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_goals');
    }
};

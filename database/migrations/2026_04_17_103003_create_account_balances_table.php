<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->date('as_of');
            $table->decimal('balance', 18, 4);
            $table->string('currency', 3);
            $table->string('source')->default('computed'); // computed|imported|manual
            $table->timestamps();
            $table->unique(['account_id', 'as_of']);
            $table->index(['as_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_balances');
    }
};

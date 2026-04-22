<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_hub_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            // Hub slug — 'pets', 'planning', 'ledger', etc. Free-form
            // string so new hubs don't need a migration.
            $table->string('hub_name');
            $table->string('active_tab');
            $table->timestamps();
            $table->unique(['user_id', 'household_id', 'hub_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_hub_preferences');
    }
};

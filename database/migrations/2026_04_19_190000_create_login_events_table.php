<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Record the email even on failures, since there's no user row yet.
            $table->string('email')->nullable();
            // password | magic_link | passkey | social:google | social:github | social:microsoft | social:apple
            $table->string('method', 32);
            $table->boolean('succeeded')->default(false);
            $table->string('reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['succeeded', 'created_at']);
            $table->index('ip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};

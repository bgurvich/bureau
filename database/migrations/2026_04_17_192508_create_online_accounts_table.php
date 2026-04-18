<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital-footprint catalogue: every online account the user signs into.
 * Explicit non-goal: not a password vault — we store where the account lives
 * and how to recover it, not the secret itself (see ROADMAP out-of-scope).
 * Feeds the in-case-of pack and subscription reconciliation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('service_name');
            $table->string('url')->nullable();
            $table->string('login_email')->nullable();
            $table->string('username')->nullable();
            $table->string('mfa_method')->default('none'); // none|totp|sms|app_push|email|security_key|passkey
            $table->foreignId('recovery_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('linked_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('importance_tier')->default('medium'); // critical|high|medium|low
            $table->boolean('in_case_of_pack')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'importance_tier']);
            $table->index('service_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_accounts');
    }
};

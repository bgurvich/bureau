<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('counterparty_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            // One subscription wraps one recurring outflow rule. Multiple
            // subscriptions can share a contract (e.g. "Microsoft 365" pays
            // annually via rule A, plus an add-on via rule B), so the rule
            // is the 1:1 anchor and contract is optional.
            $table->foreignId('recurring_rule_id')->nullable()->constrained('recurring_rules')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('state', 16)->default('active');     // active | paused | cancelled
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->decimal('monthly_cost_cached', 14, 4)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'state']);
            $table->unique('recurring_rule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

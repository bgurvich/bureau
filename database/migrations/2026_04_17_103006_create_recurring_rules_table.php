<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // bill|income|transfer|maintenance|warranty_expiry|event
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('amount', 18, 4)->nullable(); // signed where applicable
            $table->string('currency', 3)->nullable();
            $table->string('rrule'); // RFC-5545 RRULE string
            $table->date('dtstart');
            $table->date('until')->nullable();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('accounts')->nullOnDelete(); // for transfers
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('counterparty_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->nullableMorphs('subject'); // optional Property/Vehicle/InventoryItem/Pet owner
            $table->unsignedSmallInteger('lead_days')->default(7); // notice window for the attention radar
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['household_id', 'kind', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_rules');
    }
};

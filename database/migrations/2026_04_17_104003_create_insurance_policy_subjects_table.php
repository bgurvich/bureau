<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_policy_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('insurance_policies')->cascadeOnDelete();
            $table->morphs('subject'); // Vehicle|Property|User|Pet|InventoryItem
            $table->string('role')->default('covered'); // covered|beneficiary|dependent|named_insured
            $table->timestamps();
            $table->unique(['policy_id', 'subject_type', 'subject_id', 'role'], 'ips_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_policy_subjects');
    }
};

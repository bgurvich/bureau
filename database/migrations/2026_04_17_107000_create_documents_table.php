<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // passport|license|id_card|will|poa|advance_directive|pet_license|birth_cert|ssn|other
            $table->foreignId('holder_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label')->nullable();
            $table->string('number')->nullable();
            $table->string('issuer')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->boolean('in_case_of_pack')->default(false); // include in the emergency bundle
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'kind']);
            $table->index(['household_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};

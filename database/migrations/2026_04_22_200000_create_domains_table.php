<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owned web / DNS domains. Matches the shape of Property / Vehicle /
 * InventoryItem — first-class on the Assets hub, same household-
 * scoped + tag-able + note-able conventions. Expiries feed the
 * attention radar like any other dated asset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('registrar')->nullable();
            $table->date('registered_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->boolean('auto_renew')->default(false);

            // Free-form, one per line; stored as text so updates to the
            // provider's nameservers are a simple paste-over, not a
            // relational dance.
            $table->text('nameservers')->nullable();

            $table->decimal('annual_cost', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();

            // Registrant identity (usually a contact / org). Nullable
            // because a fresh domain might be logged before the contact
            // row exists; the inspector's inline-create flow fills it in.
            $table->foreignId('registrant_contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['household_id', 'name']);
            $table->index(['household_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};

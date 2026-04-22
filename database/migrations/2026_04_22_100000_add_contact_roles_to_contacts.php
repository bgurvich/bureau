<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // JSON array of role slugs (family, friend, colleague,
            // emergency_contact, landlord, …). Multi-value by design —
            // a contact can be both "friend" and "colleague". Enum-shaped
            // booleans (is_vendor / is_customer / favorite) stay as-is;
            // roles are the free-form relationship axis.
            $table->json('contact_roles')->nullable()->after('is_customer');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('contact_roles');
        });
    }
};

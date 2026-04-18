<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('registration_expires_on')->nullable()->after('license_jurisdiction');
            $table->decimal('registration_fee_amount', 12, 2)->nullable()->after('registration_expires_on');
            $table->string('registration_fee_currency', 3)->nullable()->after('registration_fee_amount');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['registration_expires_on', 'registration_fee_amount', 'registration_fee_currency']);
        });
    }
};

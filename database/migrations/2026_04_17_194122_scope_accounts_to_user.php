<?php

use App\Models\Household;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts (bank, credit, cash, investment, loan, mortgage, gift_card, prepaid)
 * and online_accounts are per-user, not shared across a household. household_id
 * stays as the tenant boundary; user_id is the ownership axis used for
 * per-user net-worth surfaces and spouse-can't-see-my-Gmail privacy.
 *
 * Backfill maps each existing row to its household's first owner user, so
 * single-user households flow through unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('household_id')->constrained('users')->nullOnDelete();
            $table->index(['user_id', 'is_active']);
        });

        Schema::table('online_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('household_id')->constrained('users')->nullOnDelete();
            $table->index(['user_id', 'importance_tier']);
        });

        foreach (Household::with(['users' => fn ($q) => $q->wherePivot('role', 'owner')->orderBy('users.id')])->get() as $household) {
            $ownerId = $household->users->first()?->id
                ?? $household->users()->orderBy('users.id')->first()?->id;

            if (! $ownerId) {
                continue;
            }

            DB::table('accounts')->where('household_id', $household->id)->whereNull('user_id')->update(['user_id' => $ownerId]);
            DB::table('online_accounts')->where('household_id', $household->id)->whereNull('user_id')->update(['user_id' => $ownerId]);
        }
    }

    public function down(): void
    {
        Schema::table('online_accounts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'importance_tier']);
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_active']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};

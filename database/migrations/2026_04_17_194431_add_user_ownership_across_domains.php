<?php

use App\Models\Household;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user ownership hooks on household-scoped domains. Naming matches the
 * existing pattern from vehicles.primary_user_id / documents.holder_user_id /
 * tasks.assigned_user_id — each FK carries the semantic of "whose is this?"
 * while the row still lives in the household (so joint views aggregate and
 * spouse-level ACL can reuse it later).
 *
 *   meetings.organizer_user_id   — whose calendar hosts this
 *   contacts.owner_user_id        — whose personal contact (vs family-shared)
 *   contracts.primary_user_id     — who signed / is liable
 *   properties.primary_user_id    — primary owner on the deed
 *   inventory_items.owner_user_id — personal belonging vs household-shared
 *
 * Each is nullable — NULL means "shared household-wide". Backfill sets
 * existing rows to the household's first owner so single-user households
 * see no change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->foreignId('organizer_user_id')->nullable()->after('household_id')->constrained('users')->nullOnDelete();
            $table->index(['household_id', 'organizer_user_id']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('household_id')->constrained('users')->nullOnDelete();
            $table->index(['household_id', 'owner_user_id']);
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('primary_user_id')->nullable()->after('household_id')->constrained('users')->nullOnDelete();
            $table->index(['household_id', 'primary_user_id']);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('primary_user_id')->nullable()->after('household_id')->constrained('users')->nullOnDelete();
            $table->index(['household_id', 'primary_user_id']);
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('household_id')->constrained('users')->nullOnDelete();
            $table->index(['household_id', 'owner_user_id']);
        });

        foreach (Household::with(['users' => fn ($q) => $q->orderBy('users.id')])->get() as $household) {
            $ownerId = $household->users->first()?->id;
            if (! $ownerId) {
                continue;
            }

            foreach ([
                ['meetings', 'organizer_user_id'],
                ['contacts', 'owner_user_id'],
                ['contracts', 'primary_user_id'],
                ['properties', 'primary_user_id'],
                ['inventory_items', 'owner_user_id'],
            ] as [$table, $column]) {
                DB::table($table)
                    ->where('household_id', $household->id)
                    ->whereNull($column)
                    ->update([$column => $ownerId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'owner_user_id']);
            $table->dropConstrainedForeignId('owner_user_id');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'primary_user_id']);
            $table->dropConstrainedForeignId('primary_user_id');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'primary_user_id']);
            $table->dropConstrainedForeignId('primary_user_id');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'owner_user_id']);
            $table->dropConstrainedForeignId('owner_user_id');
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'organizer_user_id']);
            $table->dropConstrainedForeignId('organizer_user_id');
        });
    }
};

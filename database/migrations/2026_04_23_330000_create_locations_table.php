<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hierarchical physical locations — house → room → shelf → box → item.
 * Self-referential parent_id lets the tree grow arbitrarily deep.
 * property_id anchors the root of a tree to a real property (useful
 * for filtering "everything in my house" vs. "everything at the
 * cabin") while allowing location-less rows for storage that lives
 * outside any tracked property (a friend's garage, a storage unit
 * that was never modeled as a Property).
 *
 * kind discriminates so the UI can render the right icon / affordance
 * without forcing the user to pick between redundant categories. Free
 * string because physical taxonomies mutate with the household.
 *
 * Backfills an initial Location row for every distinct (property, room)
 * pair already referenced by inventory_items, then stamps each item's
 * new location_id FK to match. Pre-existing `room` stays as a fallback
 * label column on inventory_items — dropping it is a later cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('name');
            $table->string('kind', 32)->default('other'); // area|room|container|other
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['household_id', 'parent_id', 'position']);
            $table->index(['household_id', 'property_id']);
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('location_property_id')
                ->constrained('locations')->nullOnDelete();
            $table->index(['household_id', 'location_id']);
        });

        // Backfill: group items by (household_id, location_property_id, room)
        // and create one Location per unique triple.
        $groups = DB::table('inventory_items')
            ->select('household_id', 'location_property_id', 'room')
            ->whereNotNull('room')
            ->where('room', '!=', '')
            ->groupBy('household_id', 'location_property_id', 'room')
            ->get();

        foreach ($groups as $g) {
            $locId = DB::table('locations')->insertGetId([
                'household_id' => $g->household_id,
                'property_id' => $g->location_property_id,
                'parent_id' => null,
                'name' => $g->room,
                'kind' => 'room',
                'position' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('inventory_items')
                ->where('household_id', $g->household_id)
                ->where('room', $g->room)
                ->when($g->location_property_id !== null,
                    fn ($q) => $q->where('location_property_id', $g->location_property_id),
                    fn ($q) => $q->whereNull('location_property_id')
                )
                ->update(['location_id' => $locId]);
        }
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'location_id']);
            $table->dropConstrainedForeignId('location_id');
        });
        Schema::dropIfExists('locations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local tracker for items put up for sale on any platform. One row
 * per (item, platform) posting — not tied to the platform's API, so
 * "I listed this on Craigslist at $X, here's the URL" can be logged
 * without having to wire OAuth and bulk-post XML first.
 *
 * inventory_item_id is nullable so listings for stuff NOT in the
 * inventory (a service, a rental, a one-off) still work. Auto-post
 * wiring lands later when the eBay Sell API + Craigslist bulkpost
 * credentials are acquired; external_id will carry the platform's
 * posting id once that lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();

            $table->string('platform', 32);        // ebay | craigslist | facebook_marketplace | offerup | mercari | other
            $table->string('status', 16)->default('draft'); // draft | live | sold | expired | cancelled

            $table->string('title');

            $table->decimal('price', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->string('external_url', 2048)->nullable();
            $table->string('external_id', 128)->nullable();

            $table->date('posted_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->date('ended_on')->nullable();

            $table->decimal('sold_for', 14, 2)->nullable();
            $table->foreignId('sold_to_contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['household_id', 'status']);
            $table->index(['household_id', 'platform', 'status']);
            $table->index(['inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};

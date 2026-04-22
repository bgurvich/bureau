<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per filed (or to-be-filed) tax year. Jurisdiction is coarse
 * for v1 — 'US-federal' / 'US-<state>' — enough to separate the two
 * income-tax returns most users file annually. Filing status is held
 * as a free string since it varies by jurisdiction and we're not yet
 * doing any automated calculation.
 *
 * The tax_documents + tax_estimated_payments tables below hang off the
 * tax_year_id FK. Deleting a year cascades its children; the user
 * should rarely delete a filed year, but early-drafts are expected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            $table->smallInteger('year')->unsigned();
            $table->string('jurisdiction', 32)->default('US-federal');
            $table->string('filing_status', 32)->nullable();

            // Lifecycle: prep → filed → amended. Free-form so adding
            // 'extended' / 'audited' later doesn't require a migration.
            $table->string('state', 32)->default('prep');

            $table->date('filed_on')->nullable();

            // Positive = refund owed to user; negative = user owes tax.
            // One column instead of two (refund_amount / owed_amount) so
            // aggregates work without a coalesce dance.
            $table->decimal('settlement_amount', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['household_id', 'year', 'jurisdiction']);
            $table->index(['household_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_years');
    }
};

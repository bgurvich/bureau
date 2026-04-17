<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // net_worth|cashflow|category_totals|forecast|subscription_burn|...
            $table->nullableMorphs('subject'); // optional subject: Account, Category, Contract, or household-wide if null
            $table->date('taken_on');
            $table->string('period')->default('point'); // point|daily|weekly|monthly|yearly|forecast_30d|forecast_90d
            $table->json('payload'); // numeric rollups, distribution, forecast bands, whatever the kind needs
            $table->string('source')->default('scheduled'); // scheduled|manual|on_demand
            $table->timestamps();
            $table->index(['household_id', 'kind', 'taken_on']);
            $table->index(['subject_type', 'subject_id', 'kind', 'taken_on'], 'snapshots_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};

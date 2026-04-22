<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-document checklist row under a tax year — W-2, the various 1099
 * flavours, K-1, receipts, etc. Not every doc has an amount on its
 * face (e.g. a receipt bundle), so `amount` is nullable. The actual
 * scan attaches via the standard polymorphic media pivot, keyed off
 * subject_type = App\Models\TaxDocument.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_year_id')->constrained('tax_years')->cascadeOnDelete();

            // Discriminator. Using a string keeps the list editable; the
            // inspector form offers a picker with the common shapes:
            // W-2, 1099-NEC, 1099-MISC, 1099-INT, 1099-DIV, 1099-R, 1099-B,
            // 1099-G, 1098, K-1, receipt, schedule, other.
            $table->string('kind', 32);

            $table->string('label')->nullable();

            // Issuing party (usually an employer or financial institution).
            // Nullable for receipt-only captures where the vendor is
            // captured on the linked Media row instead.
            $table->foreignId('from_contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->date('received_on')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['tax_year_id', 'kind']);
            $table->index(['tax_year_id', 'received_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_documents');
    }
};

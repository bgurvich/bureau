<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Contact;
use App\Models\Transaction;

/**
 * Auto-applies the counterparty's default category to a transaction
 * that doesn't already have one. Runs in TransactionObserver before
 * CategoryRuleMatcher so contact-level defaults win over user-defined
 * description pattern rules — a vendor is a stronger signal than a
 * description substring. Explicit category_id on the row (set at
 * create time by the user or import) still wins over both.
 */
final class ContactCategoryApplier
{
    public static function attempt(Transaction $transaction): void
    {
        if ($transaction->category_id) {
            return;
        }
        if (! $transaction->counterparty_contact_id) {
            return;
        }

        $contact = Contact::query()
            ->whereKey($transaction->counterparty_contact_id)
            ->first(['id', 'category_id']);

        if ($contact === null || $contact->category_id === null) {
            return;
        }

        $transaction->forceFill(['category_id' => $contact->category_id])->save();
    }
}

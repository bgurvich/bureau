<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Transaction;
use Livewire\Livewire;

it('applies the counterparty contact default category to a new transaction', function () {
    authedInHousehold();

    $account = Account::create([
        'type' => 'checking', 'name' => 'Chase', 'currency' => 'USD', 'opening_balance' => 0,
    ]);
    $groceries = Category::create(['kind' => 'expense', 'name' => 'Groceries', 'slug' => 'groceries']);
    $contact = Contact::create([
        'kind' => 'org', 'display_name' => 'Costco',
        'is_vendor' => true, 'category_id' => $groceries->id,
    ]);

    $t = Transaction::create([
        'account_id' => $account->id,
        'counterparty_contact_id' => $contact->id,
        'occurred_on' => '2026-04-21',
        'amount' => -42, 'currency' => 'USD',
        'description' => 'Costco run', 'status' => 'cleared',
    ]);

    expect($t->fresh()->category_id)->toBe($groceries->id);
});

it('does not override an explicit category_id on the transaction', function () {
    authedInHousehold();

    $account = Account::create([
        'type' => 'checking', 'name' => 'Chase', 'currency' => 'USD', 'opening_balance' => 0,
    ]);
    $groceries = Category::create(['kind' => 'expense', 'name' => 'Groceries', 'slug' => 'groceries']);
    $dining = Category::create(['kind' => 'expense', 'name' => 'Dining', 'slug' => 'dining']);
    $contact = Contact::create([
        'kind' => 'org', 'display_name' => 'Costco',
        'category_id' => $groceries->id,
    ]);

    $t = Transaction::create([
        'account_id' => $account->id,
        'counterparty_contact_id' => $contact->id,
        'category_id' => $dining->id,
        'occurred_on' => '2026-04-21',
        'amount' => -42, 'currency' => 'USD',
        'description' => 'Costco run',
    ]);

    expect($t->fresh()->category_id)->toBe($dining->id);
});

it('does nothing when the contact has no default category', function () {
    authedInHousehold();

    $account = Account::create([
        'type' => 'checking', 'name' => 'Chase', 'currency' => 'USD', 'opening_balance' => 0,
    ]);
    $contact = Contact::create([
        'kind' => 'org', 'display_name' => 'Costco',
    ]);

    $t = Transaction::create([
        'account_id' => $account->id,
        'counterparty_contact_id' => $contact->id,
        'occurred_on' => '2026-04-21',
        'amount' => -42, 'currency' => 'USD',
        'description' => 'Costco run',
    ]);

    expect($t->fresh()->category_id)->toBeNull();
});

it('backfills uncategorised transactions when triggered from the contact inspector', function () {
    authedInHousehold();

    $account = Account::create([
        'type' => 'checking', 'name' => 'Chase', 'currency' => 'USD', 'opening_balance' => 0,
    ]);
    $groceries = Category::create(['kind' => 'expense', 'name' => 'Groceries', 'slug' => 'groceries']);
    $dining = Category::create(['kind' => 'expense', 'name' => 'Dining', 'slug' => 'dining']);
    $contact = Contact::create(['kind' => 'org', 'display_name' => 'Costco']);

    // Pre-existing transactions: two with no category, one explicitly
    // categorised as Dining (should NOT be overwritten).
    $uncat1 = Transaction::create([
        'account_id' => $account->id, 'counterparty_contact_id' => $contact->id,
        'occurred_on' => '2026-04-10', 'amount' => -10, 'currency' => 'USD',
        'description' => 'Costco 1',
    ]);
    $uncat2 = Transaction::create([
        'account_id' => $account->id, 'counterparty_contact_id' => $contact->id,
        'occurred_on' => '2026-04-15', 'amount' => -20, 'currency' => 'USD',
        'description' => 'Costco 2',
    ]);
    $explicit = Transaction::create([
        'account_id' => $account->id, 'counterparty_contact_id' => $contact->id,
        'occurred_on' => '2026-04-18', 'amount' => -15, 'currency' => 'USD',
        'description' => 'Costco 3', 'category_id' => $dining->id,
    ]);

    // Set the contact's category via the inspector and run the backfill.
    // Backfill persists the category itself so the user can hit Apply
    // without a prior Save click — drawer stays open to show the status.
    Livewire::test('inspector')
        ->call('openInspector', 'contact', $contact->id)
        ->set('contact_category_id', $groceries->id)
        ->call('backfillCategoryToTransactions');

    expect($uncat1->fresh()->category_id)->toBe($groceries->id)
        ->and($uncat2->fresh()->category_id)->toBe($groceries->id)
        ->and($explicit->fresh()->category_id)->toBe($dining->id);
});

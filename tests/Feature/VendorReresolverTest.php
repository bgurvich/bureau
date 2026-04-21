<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Contact;
use App\Models\Transaction;
use App\Support\CurrentHousehold;
use App\Support\VendorReresolver;

function reresolverAccount(): Account
{
    return Account::create([
        'type' => 'checking', 'name' => 'Everyday',
        'currency' => 'USD', 'opening_balance' => 0,
    ]);
}

it('moves transactions from an ugly auto-contact to a clean one after ignore patterns are added', function () {
    authedInHousehold();
    $account = reresolverAccount();

    // Simulate the pre-cleanup state: user imported two Costco rows
    // before setting up ignore patterns, so both point at an
    // auto-created "Purchase Authorized" contact.
    $ugly = Contact::create(['kind' => 'org', 'display_name' => 'Purchase Authorized', 'is_vendor' => true]);
    foreach (['Purchase authorized on 07/30 Costco #123', 'Purchase authorized on 08/02 Costco #456'] as $desc) {
        Transaction::create([
            'account_id' => $account->id,
            'occurred_on' => '2026-07-30',
            'amount' => -50,
            'currency' => 'USD',
            'description' => $desc,
            'status' => 'cleared',
            'counterparty_contact_id' => $ugly->id,
        ]);
    }

    // User now adds an ignore pattern that strips the filler phrase
    // AND the date, so the real vendor name survives the subsequent
    // digit-cut inside fingerprint().
    $h = CurrentHousehold::get();
    $h->forceFill(['data' => ['vendor_ignore_patterns' => 'purchase authorized on \d+/\d+']])->save();

    $summary = VendorReresolver::run();

    // Both rows should have moved off the ugly contact to a newly
    // created "Costco" contact.
    $costco = Contact::where('display_name', 'Costco')->first();
    expect($costco)->not->toBeNull()
        ->and(Transaction::where('counterparty_contact_id', $costco->id)->count())->toBe(2)
        ->and(Transaction::where('counterparty_contact_id', $ugly->id)->count())->toBe(0)
        ->and($summary['created'])->toBe(1)
        ->and($summary['touched'])->toBe(2);
});

it('preserves manual counterparty assignments whose name does not match the description', function () {
    authedInHousehold();
    $account = reresolverAccount();

    // User manually set a specific contact that has nothing to do
    // with the description — re-resolver must NOT override it.
    $manual = Contact::create(['kind' => 'org', 'display_name' => 'Friend Paul', 'is_vendor' => false]);
    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-07-30',
        'amount' => -50,
        'currency' => 'USD',
        'description' => 'Wire transfer splitting dinner',
        'status' => 'cleared',
        'counterparty_contact_id' => $manual->id,
    ]);

    $summary = VendorReresolver::run();

    expect(Transaction::first()->counterparty_contact_id)->toBe($manual->id)
        ->and($summary['skipped_manual'])->toBe(1)
        ->and($summary['touched'])->toBe(0);
});

it('re-resolves to an existing matching contact rather than creating a duplicate', function () {
    authedInHousehold();
    $account = reresolverAccount();

    // Clean vendor already exists.
    $netflix = Contact::create(['kind' => 'org', 'display_name' => 'Netflix', 'is_vendor' => true]);

    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-07-30',
        'amount' => -15.99,
        'currency' => 'USD',
        'description' => 'NETFLIX.COM 01',
        'status' => 'cleared',
        // Starts unassigned.
    ]);

    $summary = VendorReresolver::run();

    expect(Transaction::first()->counterparty_contact_id)->toBe($netflix->id)
        ->and(Contact::count())->toBe(1)
        ->and($summary['matched_existing'])->toBe(1);
});

it('clears a stale auto-counterparty when the row\'s new fingerprint no longer matches anything', function () {
    authedInHousehold();
    $account = reresolverAccount();

    // Old auto-contact whose name fingerprints to "purchase" (the
    // same fingerprint the row used to produce) — heuristic treats
    // this as auto-set and safe to clear.
    $stale = Contact::create(['kind' => 'org', 'display_name' => 'Purchase', 'is_vendor' => true]);
    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-07-30',
        'amount' => -12,
        'currency' => 'USD',
        'description' => 'Purchase',
        'status' => 'cleared',
        'counterparty_contact_id' => $stale->id,
    ]);

    // Add a pattern that strips the whole description — fingerprint
    // becomes empty, nothing to match.
    $h = CurrentHousehold::get();
    $h->forceFill(['data' => ['vendor_ignore_patterns' => 'purchase']])->save();

    $summary = VendorReresolver::run();

    expect(Transaction::first()->counterparty_contact_id)->toBeNull()
        ->and($summary['cleared'])->toBe(1);
});

it('does not touch a row whose counterparty already matches its current fingerprint', function () {
    authedInHousehold();
    $account = reresolverAccount();

    $costco = Contact::create(['kind' => 'org', 'display_name' => 'Costco', 'is_vendor' => true]);
    // Description whose first meaningful word fingerprints to
    // "costco" — matches the one-word contact fingerprint exactly.
    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-07-30',
        'amount' => -50,
        'currency' => 'USD',
        'description' => 'Costco #42',
        'status' => 'cleared',
        'counterparty_contact_id' => $costco->id,
    ]);

    $summary = VendorReresolver::run();

    expect($summary['touched'])->toBe(0)
        ->and(Transaction::first()->counterparty_contact_id)->toBe($costco->id);
});

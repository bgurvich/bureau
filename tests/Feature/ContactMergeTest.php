<?php

use App\Models\Account;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Transaction;
use App\Support\ContactMerge;

it('repoints single-FK columns from loser to winner and deletes loser', function () {
    authedInHousehold();
    $winner = Contact::create(['kind' => 'org', 'display_name' => 'Chase (canonical)']);
    $loser = Contact::create(['kind' => 'org', 'display_name' => 'Chase Bank — duplicate']);

    $account = Account::create([
        'type' => 'checking', 'name' => 'Chase Checking',
        'currency' => 'USD', 'opening_balance' => 0,
        'vendor_contact_id' => $loser->id,
    ]);
    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-01-15',
        'amount' => -100.00,
        'currency' => 'USD',
        'description' => 'ATM',
        'status' => 'cleared',
        'counterparty_contact_id' => $loser->id,
    ]);

    $survivor = ContactMerge::run($winner, $loser);

    expect($survivor->id)->toBe($winner->id)
        ->and(Contact::find($loser->id))->toBeNull()
        ->and($account->fresh()->vendor_contact_id)->toBe($winner->id)
        ->and(Transaction::where('counterparty_contact_id', $winner->id)->count())->toBe(1);
});

it('dedupes pivot attachments so the winner does not collide on a unique index', function () {
    authedInHousehold();
    $winner = Contact::create(['kind' => 'org', 'display_name' => 'Winner']);
    $loser = Contact::create(['kind' => 'org', 'display_name' => 'Loser']);

    $contract = Contract::create([
        'kind' => 'agreement',
        'title' => 'NDA',
        'state' => 'active',
    ]);
    // Both contacts already on the same contract — a naive UPDATE would hit
    // the (contact_id, contract_id) unique index. The merge drops the
    // collision row first, then updates the rest.
    $contract->contacts()->attach($winner->id, ['party_role' => 'counterparty']);
    $contract->contacts()->attach($loser->id, ['party_role' => 'witness']);

    ContactMerge::run($winner, $loser);

    expect($contract->fresh()->contacts()->where('contacts.id', $winner->id)->count())->toBe(1)
        ->and(Contact::find($loser->id))->toBeNull();
});

it('is a no-op when winner and loser are the same contact', function () {
    authedInHousehold();
    $c = Contact::create(['kind' => 'org', 'display_name' => 'Solo']);

    $survivor = ContactMerge::run($c, $c);

    expect($survivor->id)->toBe($c->id)
        ->and(Contact::find($c->id))->not->toBeNull();
});

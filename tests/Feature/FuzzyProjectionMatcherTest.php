<?php

use App\Models\Account;
use App\Models\Contact;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Support\ProjectionMatcher;

it('materializes a projection for a recurring rule when no exact projection exists', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    $contact = Contact::create(['kind' => 'org', 'display_name' => 'NETFLIX']);
    RecurringRule::create([
        'kind' => 'bill', 'title' => 'Netflix subscription',
        'amount' => -15.99, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=15',
        'dtstart' => '2026-01-15',
        'account_id' => $account->id,
        'counterparty_contact_id' => $contact->id,
        'active' => true,
    ]);

    // No pre-existing RecurringProjection for this cycle.
    $txn = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-03-15',
        'amount' => -15.99,
        'currency' => 'USD',
        'description' => 'NETFLIX.COM 12345',
        'status' => 'cleared',
        'counterparty_contact_id' => $contact->id,
    ]);

    $projection = ProjectionMatcher::attempt($txn);
    expect($projection)->not->toBeNull()
        ->and($projection->matched_transaction_id)->toBe($txn->id)
        ->and($projection->status)->toBe('matched');
});

it('matches via description tokens when counterparty is absent', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    RecurringRule::create([
        'kind' => 'bill', 'title' => 'Spotify Premium',
        'amount' => -9.99, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=10',
        'dtstart' => '2026-01-10',
        'account_id' => $account->id,
        'active' => true,
    ]);

    $txn = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-03-11',
        'amount' => -9.99,
        'currency' => 'USD',
        'description' => 'SPOTIFY USA 98765',
        'status' => 'cleared',
    ]);

    $p = ProjectionMatcher::attempt($txn);
    expect($p)->not->toBeNull();
});

it('amount band of ±10% is respected (12 vs 10 NOT matched)', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    RecurringRule::create([
        'kind' => 'bill', 'title' => 'Gym',
        'amount' => -10.00, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        'dtstart' => '2026-01-01',
        'account_id' => $account->id,
        'active' => true,
    ]);

    // -12 is >10% away from -10 → should not match.
    $txn = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-03-01',
        'amount' => -12.00,
        'currency' => 'USD',
        'description' => 'GYM MEMBERSHIP',
        'status' => 'cleared',
    ]);

    expect(ProjectionMatcher::attempt($txn))->toBeNull();
});

it('exact projection match still takes priority over fuzzy fallback', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Rent',
        'amount' => -2000, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        'dtstart' => '2026-01-01',
        'account_id' => $account->id,
        'active' => true,
    ]);
    $pre = RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => '2026-03-01',
        'issued_on' => '2026-03-01',
        'amount' => -2000,
        'currency' => 'USD',
        'status' => 'projected',
    ]);

    $txn = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-03-01',
        'amount' => -2000,
        'currency' => 'USD',
        'description' => 'Rent',
        'status' => 'cleared',
    ]);

    $p = ProjectionMatcher::attempt($txn);
    expect($p)->not->toBeNull()
        ->and($p->id)->toBe($pre->id);
});

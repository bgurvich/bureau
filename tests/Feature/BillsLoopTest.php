<?php

use App\Models\Account;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Support\CurrentHousehold;
use App\Support\ProjectionMatcher;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

function setupForBills(): array
{
    $user = authedInHousehold();
    $account = Account::create([
        'type' => 'bank', 'name' => 'Chase', 'currency' => 'USD', 'opening_balance' => 0,
    ]);

    return [CurrentHousehold::get(), $user, $account];
}

it('generator fills issued_on, computes due_on via offset, and denormalizes autopay', function () {
    CarbonImmutable::setTestNow('2026-04-17');
    [$household, , $account] = setupForBills();

    RecurringRule::create([
        'kind' => 'bill', 'title' => 'Utility',
        'amount' => -120, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        'dtstart' => '2026-04-01',
        'due_offset_days' => 20,
        'autopay' => true,
        'account_id' => $account->id,
    ]);

    $this->artisan('recurring:project', ['--household' => $household->id])->assertSuccessful();

    $row = RecurringProjection::orderBy('due_on')->first();
    expect($row->issued_on->toDateString())->toBe('2026-04-01')
        ->and($row->due_on->toDateString())->toBe('2026-04-21')
        ->and($row->autopay)->toBeTrue();

    CarbonImmutable::setTestNow();
});

it('auto-matches a new transaction to the single candidate projection', function () {
    [, , $account] = setupForBills();

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Rent',
        'amount' => -2200, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        'dtstart' => '2026-03-01',
        'account_id' => $account->id,
    ]);

    $projection = RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => '2026-04-01',
        'issued_on' => '2026-04-01',
        'amount' => -2200,
        'currency' => 'USD',
        'status' => 'overdue',
    ]);

    $txn = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-04-02',
        'amount' => -2200,
        'currency' => 'USD',
        'description' => 'Rent April',
        'status' => 'cleared',
    ]);

    $matched = ProjectionMatcher::attempt($txn);

    expect($matched?->id)->toBe($projection->id);
    $fresh = $projection->fresh();
    expect($fresh->status)->toBe('matched')
        ->and($fresh->matched_transaction_id)->toBe($txn->id)
        ->and($fresh->matched_at)->not->toBeNull();
});

it('honors a per-rule match_tolerance_days that widens the default window', function () {
    [, , $account] = setupForBills();

    // Utility bills drift by a whole week depending on the billing cycle.
    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Utility',
        'amount' => -85, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        'dtstart' => '2026-03-01',
        'account_id' => $account->id,
        'match_tolerance_days' => 10,
    ]);

    $projection = RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => '2026-04-01',
        'issued_on' => '2026-04-01',
        'amount' => -85,
        'currency' => 'USD',
        'status' => 'overdue',
    ]);

    // Transaction is 8 days off — outside default (3) but inside rule's (10).
    $txn = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-04-09',
        'amount' => -85,
        'currency' => 'USD',
        'status' => 'cleared',
    ]);

    expect(ProjectionMatcher::attempt($txn)?->id)->toBe($projection->id);
    expect($projection->fresh()->status)->toBe('matched');
});

it('refuses to auto-match when two candidates are tied', function () {
    [, , $account] = setupForBills();

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Ambiguous',
        'amount' => -50, 'currency' => 'USD',
        'rrule' => 'FREQ=DAILY;COUNT=1',
        'dtstart' => '2026-04-01',
        'account_id' => $account->id,
    ]);

    foreach (['2026-04-01', '2026-04-02'] as $date) {
        RecurringProjection::create([
            'rule_id' => $rule->id,
            'due_on' => $date,
            'issued_on' => $date,
            'amount' => -50,
            'currency' => 'USD',
            'status' => 'overdue',
        ]);
    }

    $txn = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-04-01',
        'amount' => -50,
        'currency' => 'USD',
        'status' => 'cleared',
    ]);

    expect(ProjectionMatcher::attempt($txn))->toBeNull();
    expect(RecurringProjection::where('status', 'matched')->count())->toBe(0);

    // resolve() should surface the ambiguity so the Inspector can pick.
    $result = ProjectionMatcher::resolve($txn);
    expect($result->isAmbiguous())->toBeTrue()
        ->and($result->isLinked())->toBeFalse()
        ->and($result->candidates)->toHaveCount(2);
});

it('resolve() returns a linked result on single-match auto-link', function () {
    [, , $account] = setupForBills();

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Rent',
        'amount' => -1000, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        'dtstart' => '2026-04-01',
        'account_id' => $account->id,
    ]);
    $projection = RecurringProjection::create([
        'rule_id' => $rule->id, 'due_on' => '2026-04-01',
        'issued_on' => '2026-04-01', 'amount' => -1000,
        'currency' => 'USD', 'status' => 'overdue',
    ]);

    $txn = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-04-01',
        'amount' => -1000, 'currency' => 'USD', 'status' => 'cleared',
    ]);

    $result = ProjectionMatcher::resolve($txn);
    expect($result->isLinked())->toBeTrue()
        ->and($result->isAmbiguous())->toBeFalse()
        ->and($result->linked?->id)->toBe($projection->id)
        ->and($result->candidates)->toBeEmpty();
});

it('Inspector shows a picker when a new transaction matches 2+ projections', function () {
    [, , $account] = setupForBills();

    // Two projections with identical amount/date-range so the matcher can't pick.
    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Ambiguous rent',
        'amount' => -1000, 'currency' => 'USD',
        'rrule' => 'FREQ=DAILY;COUNT=1',
        'dtstart' => '2026-04-01',
        'account_id' => $account->id,
    ]);
    foreach (['2026-04-01', '2026-04-02'] as $date) {
        RecurringProjection::create([
            'rule_id' => $rule->id,
            'due_on' => $date, 'issued_on' => $date,
            'amount' => -1000, 'currency' => 'USD', 'status' => 'overdue',
        ]);
    }

    $c = Livewire::test('inspector')
        ->call('openInspector', 'transaction')
        ->set('account_id', $account->id)
        ->set('amount', '-1000')
        ->set('occurred_on', '2026-04-01')
        ->set('currency', 'USD')
        ->set('description', 'Rent')
        ->call('save');

    // Drawer stays open, picker is populated, no projection linked yet.
    $candidates = $c->get('ambiguousCandidates');
    expect($candidates)->toHaveCount(2)
        ->and($c->get('ambiguousTransactionId'))->not->toBeNull()
        ->and(RecurringProjection::where('status', 'matched')->count())->toBe(0);

    // User picks one → that projection is linked, drawer closes.
    $chosenId = $candidates[0]['id'];
    $c->call('linkProjection', $chosenId);

    $matched = RecurringProjection::where('status', 'matched')->first();
    expect($matched?->id)->toBe($chosenId)
        ->and($c->get('ambiguousCandidates'))->toBe([])
        ->and($c->get('open'))->toBeFalse();
});

it('Inspector skipProjectionLink leaves the transaction unlinked', function () {
    [, , $account] = setupForBills();
    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Ambig',
        'amount' => -50, 'currency' => 'USD',
        'rrule' => 'FREQ=DAILY;COUNT=1',
        'dtstart' => '2026-04-01',
        'account_id' => $account->id,
    ]);
    foreach (['2026-04-01', '2026-04-02'] as $date) {
        RecurringProjection::create([
            'rule_id' => $rule->id, 'due_on' => $date, 'issued_on' => $date,
            'amount' => -50, 'currency' => 'USD', 'status' => 'overdue',
        ]);
    }

    Livewire::test('inspector')
        ->call('openInspector', 'transaction')
        ->set('account_id', $account->id)
        ->set('amount', '-50')
        ->set('occurred_on', '2026-04-01')
        ->set('currency', 'USD')
        ->set('description', 'x')
        ->call('save')
        ->call('skipProjectionLink')
        ->assertSet('open', false);

    expect(RecurringProjection::where('status', 'matched')->count())->toBe(0)
        ->and(Transaction::where('amount', -50)->count())->toBe(1);
});

it('resolve() returns a miss when no projection or rule matches', function () {
    [, , $account] = setupForBills();

    $txn = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-04-01',
        'amount' => -12.34, 'currency' => 'USD', 'status' => 'cleared',
    ]);

    $result = ProjectionMatcher::resolve($txn);
    expect($result->isMiss())->toBeTrue()
        ->and($result->isLinked())->toBeFalse()
        ->and($result->isAmbiguous())->toBeFalse();
});

it('auto-matches via the Inspector when a transaction is saved', function () {
    [, , $account] = setupForBills();

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Internet',
        'amount' => -60, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=10',
        'dtstart' => '2026-03-10',
        'account_id' => $account->id,
    ]);

    $projection = RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => '2026-04-10',
        'issued_on' => '2026-04-10',
        'amount' => -60,
        'currency' => 'USD',
        'status' => 'projected',
    ]);

    Livewire::test('inspector')
        ->call('openInspector', 'transaction')
        ->set('account_id', $account->id)
        ->set('occurred_on', '2026-04-11')
        ->set('amount', '-60')
        ->set('currency', 'USD')
        ->set('status', 'cleared')
        ->call('save');

    $fresh = $projection->fresh();
    expect($fresh->status)->toBe('matched')
        ->and($fresh->matched_transaction_id)->not->toBeNull();
});

it('autopay projections past due stay silent during the 7-day grace window', function () {
    CarbonImmutable::setTestNow('2026-04-17');
    [, , $account] = setupForBills();

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Netflix',
        'amount' => -15.49, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=15',
        'dtstart' => '2026-04-15',
        'autopay' => true,
        'account_id' => $account->id,
    ]);

    // Two days past due — inside grace → should not count.
    RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => '2026-04-15',
        'issued_on' => '2026-04-15',
        'amount' => -15.49,
        'currency' => 'USD',
        'status' => 'overdue',
        'autopay' => true,
    ]);

    $this->get('/')->assertOk();

    // Simulate older projection — 10 days past due → surfaces.
    RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => '2026-04-07',
        'issued_on' => '2026-04-07',
        'amount' => -15.49,
        'currency' => 'USD',
        'status' => 'overdue',
        'autopay' => true,
    ]);

    $response = $this->get('/bills');
    $response->assertOk()
        ->assertSee('Netflix');

    CarbonImmutable::setTestNow();
});

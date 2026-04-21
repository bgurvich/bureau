<?php

use App\Models\Account;
use App\Models\Media;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

function makeBillSetup(): array
{
    $user = authedInHousehold();
    $account = Account::create([
        'type' => 'bank', 'name' => 'Chase', 'currency' => 'USD', 'opening_balance' => 0,
    ]);

    return [$user, $account];
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-04-19');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('renders a thumbnail from the matched transaction receipt for paid projections', function () {
    [, $account] = makeBillSetup();

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'PG&E',
        'amount' => -86.64, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => '2026-03-17',
        'account_id' => $account->id,
    ]);

    $txn = Transaction::create([
        'account_id' => $account->id, 'occurred_on' => '2026-04-18',
        'amount' => -86.64, 'currency' => 'USD', 'status' => 'cleared',
    ]);

    $scan = Media::create([
        'disk' => 'local', 'path' => 'scans/pge-apr.png', 'original_name' => 'pge-apr.png',
        'mime' => 'image/png', 'size' => 100, 'ocr_status' => 'done',
    ]);
    $txn->media()->attach($scan->id, ['role' => 'receipt']);

    RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => '2026-04-20', 'issued_on' => '2026-04-17',
        'amount' => -86.64, 'currency' => 'USD',
        'status' => 'matched',
        'matched_transaction_id' => $txn->id,
    ]);

    Livewire::test('bills-index')
        ->assertSeeHtml('src="'.route('media.file', $scan).'"');
});

it('falls back to the rule establishing scan when the projection is unmatched', function () {
    [, $account] = makeBillSetup();

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'PG&E',
        'amount' => -86.64, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => '2026-03-17',
        'account_id' => $account->id,
    ]);

    $scan = Media::create([
        'disk' => 'local', 'path' => 'scans/pge-first.png', 'original_name' => 'pge-first.png',
        'mime' => 'image/png', 'size' => 100, 'ocr_status' => 'done',
    ]);
    $rule->media()->attach($scan->id, ['role' => 'receipt']);

    RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => '2026-04-25', 'issued_on' => '2026-04-17',
        'amount' => -86.64, 'currency' => 'USD',
        'status' => 'projected',
    ]);

    Livewire::test('bills-index')
        ->assertSeeHtml('src="'.route('media.file', $scan).'"');
});

it('renders the empty placeholder when no image media is attached', function () {
    [, $account] = makeBillSetup();

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Netflix',
        'amount' => -15.99, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => '2026-03-17',
        'account_id' => $account->id,
    ]);
    RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => '2026-04-25', 'issued_on' => '2026-04-17',
        'amount' => -15.99, 'currency' => 'USD',
        'status' => 'projected',
    ]);

    $c = Livewire::test('bills-index');
    // No <img> src pointing at a media file for this row.
    $c->assertDontSeeHtml('route(\'media.file\'')
        ->assertSeeHtml('border-dashed border-neutral-800');
});

it('ignores non-image attachments when choosing the thumbnail', function () {
    [, $account] = makeBillSetup();

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Rent',
        'amount' => -2200, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => '2026-03-01',
        'account_id' => $account->id,
    ]);
    // PDF scan — present but shouldn't be picked as a thumbnail.
    $pdf = Media::create([
        'disk' => 'local', 'path' => 'lease.pdf', 'original_name' => 'lease.pdf',
        'mime' => 'application/pdf', 'size' => 100,
    ]);
    $rule->media()->attach($pdf->id, ['role' => 'receipt']);

    RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => '2026-04-25', 'issued_on' => '2026-04-01',
        'amount' => -2200, 'currency' => 'USD',
        'status' => 'projected',
    ]);

    Livewire::test('bills-index')
        ->assertDontSeeHtml(route('media.file', $pdf))
        ->assertSeeHtml('border-dashed border-neutral-800');
});

it('prefers a receipt-role attachment when multiple images are attached to the transaction', function () {
    [, $account] = makeBillSetup();

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Dentist',
        'amount' => -180, 'currency' => 'USD',
        'rrule' => 'FREQ=DAILY;COUNT=1', 'dtstart' => '2026-04-10',
        'account_id' => $account->id,
    ]);

    $txn = Transaction::create([
        'account_id' => $account->id, 'occurred_on' => '2026-04-12',
        'amount' => -180, 'currency' => 'USD', 'status' => 'cleared',
    ]);

    $photo = Media::create([
        'disk' => 'local', 'path' => 'dental-photo.png', 'original_name' => 'dental.png',
        'mime' => 'image/png', 'size' => 100,
    ]);
    $receipt = Media::create([
        'disk' => 'local', 'path' => 'dental-receipt.png', 'original_name' => 'dental-receipt.png',
        'mime' => 'image/png', 'size' => 100,
    ]);
    $txn->media()->attach($photo->id, ['role' => 'photo']);
    $txn->media()->attach($receipt->id, ['role' => 'receipt']);

    RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => '2026-04-20', 'issued_on' => '2026-04-10',
        'amount' => -180, 'currency' => 'USD',
        'status' => 'matched',
        'matched_transaction_id' => $txn->id,
    ]);

    Livewire::test('bills-index')
        ->assertSeeHtml('src="'.route('media.file', $receipt).'"')
        ->assertDontSeeHtml('src="'.route('media.file', $photo).'"');
});

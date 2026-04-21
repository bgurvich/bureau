<?php

use App\Models\Account;
use App\Models\Media;
use App\Models\Transaction;
use App\Support\ReceiptMatcher;

function makeReceipt(array $attrs = []): Media
{
    return Media::create(array_merge([
        'disk' => 'local',
        'path' => 'r-'.uniqid().'.jpg',
        'original_name' => 'r.jpg',
        'mime' => 'image/jpeg',
        'size' => 1024,
        'ocr_status' => 'done',
        'extraction_status' => 'done',
        'ocr_text' => 'receipt',
        'ocr_extracted' => ['amount' => 24.99, 'issued_on' => '2026-03-10', 'vendor' => 'Store'],
    ], $attrs));
}

function makeAcc(): Account
{
    return Account::create(['name' => 'C', 'type' => 'checking', 'currency' => 'USD']);
}

function makeTxn(Account $a, float $amount, string $date): Transaction
{
    return Transaction::create([
        'account_id' => $a->id, 'amount' => $amount, 'currency' => 'USD',
        'occurred_on' => $date, 'description' => 'test', 'status' => 'cleared',
    ]);
}

it('matches a receipt to a single outflow transaction and attaches it', function () {
    authedInHousehold();
    $acc = makeAcc();
    $txn = makeTxn($acc, -24.99, '2026-03-09');
    $m = makeReceipt();

    expect((new ReceiptMatcher(3))->match($m))->toBe(ReceiptMatcher::MATCH_SINGLE);
    expect($txn->fresh()->media()->wherePivot('role', 'receipt')->count())->toBe(1)
        ->and($m->fresh()->processed_at)->not->toBeNull();
});

it('leaves the receipt alone when multiple candidates match', function () {
    authedInHousehold();
    $acc = makeAcc();
    makeTxn($acc, -24.99, '2026-03-09');
    makeTxn($acc, -24.99, '2026-03-11');
    $m = makeReceipt();

    expect((new ReceiptMatcher(3))->match($m))->toBe(ReceiptMatcher::MATCH_AMBIGUOUS);
    expect($m->fresh()->processed_at)->toBeNull();
});

it('returns no-match when no transaction lines up', function () {
    authedInHousehold();
    $acc = makeAcc();
    makeTxn($acc, -99.00, '2026-03-09');
    $m = makeReceipt();

    expect((new ReceiptMatcher(3))->match($m))->toBe(ReceiptMatcher::MATCH_NONE);
});

it('skips receipts without an amount or a parseable date', function () {
    authedInHousehold();
    $noAmount = makeReceipt(['ocr_extracted' => ['issued_on' => '2026-03-09']]);
    $noDate = makeReceipt(['ocr_extracted' => ['amount' => 10]]);

    expect((new ReceiptMatcher)->match($noAmount))->toBe(ReceiptMatcher::MATCH_SKIP)
        ->and((new ReceiptMatcher)->match($noDate))->toBe(ReceiptMatcher::MATCH_SKIP);
});

it('ignores transactions that already have a receipt-role media attached', function () {
    authedInHousehold();
    $acc = makeAcc();
    $txn = makeTxn($acc, -24.99, '2026-03-10');
    // Pre-attach a different receipt
    $existing = makeReceipt();
    $txn->media()->attach($existing->id, ['role' => 'receipt']);

    $newReceipt = makeReceipt();
    expect((new ReceiptMatcher(3))->match($newReceipt))->toBe(ReceiptMatcher::MATCH_NONE);
});

it('artisan receipts:match sweeps unprocessed media and prints a summary', function () {
    authedInHousehold();
    $acc = makeAcc();
    makeTxn($acc, -24.99, '2026-03-10');
    makeReceipt();

    $this->artisan('receipts:match')
        ->expectsOutputToContain('matched=1')
        ->assertSuccessful();
});

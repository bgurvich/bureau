<?php

use App\Models\Account;
use App\Models\Media;
use App\Models\Transaction;
use Livewire\Livewire;

function txnAccount(): Account
{
    authedInHousehold();

    return Account::create([
        'type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0,
    ]);
}

it('renders a thumbnail img when a receipt image is attached to a transaction', function () {
    $account = txnAccount();
    $t = Transaction::create([
        'account_id' => $account->id, 'occurred_on' => now()->toDateString(),
        'amount' => -9.99, 'currency' => 'USD', 'description' => 'Coffee', 'status' => 'cleared',
    ]);
    $scan = Media::create([
        'disk' => 'local', 'path' => 'coffee.png', 'original_name' => 'coffee.png',
        'mime' => 'image/png', 'size' => 100,
    ]);
    $t->media()->attach($scan->id, ['role' => 'receipt']);

    Livewire::test('transactions-index')
        ->assertSeeHtml('src="'.route('media.file', $scan).'"');
});

it('renders the dashed placeholder when no image media is attached', function () {
    $account = txnAccount();
    Transaction::create([
        'account_id' => $account->id, 'occurred_on' => now()->toDateString(),
        'amount' => -10.00, 'currency' => 'USD', 'description' => 'Plain', 'status' => 'cleared',
    ]);

    Livewire::test('transactions-index')
        ->assertSeeHtml('border-dashed border-neutral-800');
});

it('ignores a PDF-only attachment and renders the placeholder', function () {
    $account = txnAccount();
    $t = Transaction::create([
        'account_id' => $account->id, 'occurred_on' => now()->toDateString(),
        'amount' => -42.00, 'currency' => 'USD', 'description' => 'Consult', 'status' => 'cleared',
    ]);
    $pdf = Media::create([
        'disk' => 'local', 'path' => 'invoice.pdf', 'original_name' => 'invoice.pdf',
        'mime' => 'application/pdf', 'size' => 500,
    ]);
    $t->media()->attach($pdf->id, ['role' => 'receipt']);

    Livewire::test('transactions-index')
        ->assertDontSeeHtml(route('media.file', $pdf))
        ->assertSeeHtml('border-dashed border-neutral-800');
});

it('prefers a receipt-role attachment over a photo-role one', function () {
    $account = txnAccount();
    $t = Transaction::create([
        'account_id' => $account->id, 'occurred_on' => now()->toDateString(),
        'amount' => -50.00, 'currency' => 'USD', 'description' => 'Dentist', 'status' => 'cleared',
    ]);
    $photo = Media::create([
        'disk' => 'local', 'path' => 'selfie.png', 'original_name' => 'selfie.png',
        'mime' => 'image/png', 'size' => 100,
    ]);
    $receipt = Media::create([
        'disk' => 'local', 'path' => 'dental-receipt.png', 'original_name' => 'dental-receipt.png',
        'mime' => 'image/png', 'size' => 100,
    ]);
    $t->media()->attach($photo->id, ['role' => 'photo']);
    $t->media()->attach($receipt->id, ['role' => 'receipt']);

    Livewire::test('transactions-index')
        ->assertSeeHtml('src="'.route('media.file', $receipt).'"')
        ->assertDontSeeHtml('src="'.route('media.file', $photo).'"');
});

<?php

use App\Models\Account;
use App\Models\InventoryItem;
use App\Models\Media;
use App\Models\Transaction;
use Livewire\Livewire;

function mobileUnprocessedScan(array $overrides = []): Media
{
    return Media::create([
        'disk' => 'local', 'source' => 'mail',
        'path' => 'scans/r.png', 'original_name' => 'r.png',
        'mime' => 'image/png', 'size' => 100,
        'ocr_status' => 'done', 'ocr_text' => 'some text',
        'extraction_status' => 'done',
        'ocr_extracted' => array_replace([
            'vendor' => 'PG&E', 'amount' => 86.64,
            'issued_on' => '2026-04-17', 'category_suggestion' => 'utilities',
        ], $overrides),
    ]);
}

it('shows inbox zero when nothing awaits action', function () {
    authedInHousehold();

    $this->get(route('mobile.inbox'))
        ->assertOk()
        ->assertSee(__('Inbox zero.'));
});

it('surfaces unprocessed inventory and unprocessed OCR-extracted media', function () {
    authedInHousehold();

    InventoryItem::create(['name' => 'Unlabeled gadget', 'category' => 'other']);
    mobileUnprocessedScan();
    // Already processed: should NOT appear
    mobileUnprocessedScan(['vendor' => 'Ghost'])->forceFill(['processed_at' => now()])->save();
    InventoryItem::create(['name' => 'Already done', 'category' => 'other', 'processed_at' => now()]);

    $this->get(route('mobile.inbox'))
        ->assertOk()
        ->assertSee('Unlabeled gadget')
        ->assertSee('PG&E')
        ->assertDontSee('Already done')
        ->assertDontSee('Ghost');
});

it('bulk-creates transactions from selected scans on mobile', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Chase', 'currency' => 'USD', 'opening_balance' => 0]);
    $m = mobileUnprocessedScan(['vendor' => 'Acme', 'amount' => 25.00, 'issued_on' => '2026-04-10']);

    Livewire::test('mobile.inbox')
        ->call('toggleSelectMode')
        ->call('toggle', 'media-'.$m->id)
        ->call('openBulkTxnForm')
        ->set('bulk_account_id', $account->id)
        ->call('bulkCreateTransactions');

    expect(Transaction::count())->toBe(1)
        ->and($m->fresh()->processed_at)->not->toBeNull();
});

it('bulk-dismisses selected items on mobile', function () {
    authedInHousehold();
    $m = mobileUnprocessedScan();
    $i = InventoryItem::create(['name' => 'Thing']);

    Livewire::test('mobile.inbox')
        ->call('toggleSelectMode')
        ->call('toggle', 'media-'.$m->id)
        ->call('toggle', 'inventory-'.$i->id)
        ->call('dismissSelected');

    expect($m->fresh()->processed_at)->not->toBeNull()
        ->and($i->fresh()->processed_at)->not->toBeNull();
});

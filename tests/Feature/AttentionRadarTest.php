<?php

use App\Models\Contract;
use App\Models\InventoryItem;
use App\Models\Media;
use Livewire\Livewire;

it('counts bills inbox unprocessed scans', function () {
    authedInHousehold();
    Media::create([
        'disk' => 'local', 'path' => 'a.png', 'original_name' => 'a.png',
        'mime' => 'image/png', 'size' => 10, 'ocr_status' => 'done',
        'ocr_text' => 'x', 'extraction_status' => 'done',
        'ocr_extracted' => ['vendor' => 'A', 'amount' => 1],
    ]);
    // already processed — must not count
    Media::create([
        'disk' => 'local', 'path' => 'b.png', 'original_name' => 'b.png',
        'mime' => 'image/png', 'size' => 10, 'ocr_status' => 'done',
        'ocr_text' => 'x', 'extraction_status' => 'done',
        'ocr_extracted' => ['vendor' => 'B', 'amount' => 2],
        'processed_at' => now(),
    ]);
    // no extraction yet — must not count
    Media::create([
        'disk' => 'local', 'path' => 'c.png', 'original_name' => 'c.png',
        'mime' => 'image/png', 'size' => 10, 'ocr_status' => 'done',
    ]);

    Livewire::test('attention-radar')->assertSet('billsInbox', 1);
});

it('counts unprocessed inventory items', function () {
    authedInHousehold();
    InventoryItem::create(['name' => 'Chair']);
    InventoryItem::create(['name' => 'Done', 'processed_at' => now()]);

    Livewire::test('attention-radar')->assertSet('unprocessedInventory', 1);
});

it('includes bills inbox and unprocessed inventory in the total and renders links', function () {
    authedInHousehold();
    Media::create([
        'disk' => 'local', 'path' => 'x.png', 'original_name' => 'x.png',
        'mime' => 'image/png', 'size' => 10, 'ocr_status' => 'done',
        'ocr_text' => 'x', 'extraction_status' => 'done',
        'ocr_extracted' => ['vendor' => 'X', 'amount' => 9],
    ]);
    InventoryItem::create(['name' => 'Lamp']);

    Livewire::test('attention-radar')
        ->assertSet('total', 2)
        ->assertSee(__('Bills Inbox'))
        ->assertSee(__('Unprocessed inventory'))
        ->assertSeeHtml(route('fiscal.inbox'))
        ->assertSeeHtml(route('assets.inventory', ['status' => 'unprocessed']));
});

it('counts auto-renewing contracts with a cancellation path ending ≤ 14d', function () {
    authedInHousehold();

    // In-window with cancellation_url — counts
    Contract::create([
        'title' => 'Netflix', 'kind' => 'subscription', 'state' => 'active',
        'auto_renews' => true, 'ends_on' => now()->addDays(10),
        'cancellation_url' => 'https://netflix.com/cancel',
    ]);
    // In-window but no cancellation info — skipped (actioning is meaningless)
    Contract::create([
        'title' => 'Obscure SaaS', 'kind' => 'subscription', 'state' => 'active',
        'auto_renews' => true, 'ends_on' => now()->addDays(5),
    ]);
    // Not auto-renewing — skipped
    Contract::create([
        'title' => 'Membership', 'kind' => 'subscription', 'state' => 'active',
        'auto_renews' => false, 'ends_on' => now()->addDays(7),
        'cancellation_email' => 'cancel@x.com',
    ]);
    // Outside 14d — skipped
    Contract::create([
        'title' => 'NYT', 'kind' => 'subscription', 'state' => 'active',
        'auto_renews' => true, 'ends_on' => now()->addDays(30),
        'cancellation_url' => 'https://nyt.com/cancel',
    ]);

    Livewire::test('attention-radar')
        ->assertSet('autorenewingContractsEndingSoon', 1)
        ->assertSee('Auto-renewing');
});

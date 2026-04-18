<?php

use App\Models\InventoryItem;
use App\Models\Media;
use App\Models\Note;

it('shows an empty state when nothing has been captured', function () {
    authedInHousehold();

    $this->get(route('mobile.inbox'))
        ->assertOk()
        ->assertSee(__('Nothing here yet.'));
});

it('surfaces recent unprocessed inventory, untagged media, and notes', function () {
    $user = authedInHousehold();

    $inv = InventoryItem::create(['name' => 'Unlabeled gadget', 'category' => 'other']);
    $media = Media::create([
        'disk' => 'local', 'path' => 'scan.jpg',
        'original_name' => 'scan.jpg', 'mime' => 'image/jpeg', 'size' => 10,
        'ocr_status' => 'pending',
    ]);
    $note = Note::create(['user_id' => $user->id, 'body' => 'Remember the milk.']);

    $this->get(route('mobile.inbox'))
        ->assertOk()
        ->assertSee('Unlabeled gadget')
        ->assertSee('scan.jpg')
        ->assertSee('Remember the milk.')
        ->assertSee(__('OCR pending'));

    // Inventory that was already processed should NOT appear.
    $processed = InventoryItem::create(['name' => 'Already done', 'category' => 'other', 'processed_at' => now()]);
    $this->get(route('mobile.inbox'))->assertDontSee('Already done');
});

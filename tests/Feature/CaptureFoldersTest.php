<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\MediaFolder;
use App\Support\CurrentHousehold;
use App\Support\MediaFolders;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('auto-seeds folders on first lookup', function () {
    authedInHousehold();

    // No folders exist yet for this household.
    expect(MediaFolder::count())->toBe(0);

    $id = MediaFolders::idFor(MediaFolders::RECEIPTS);

    expect($id)->toBeInt()
        ->and(MediaFolder::find($id)?->path)->toBe('receipts')
        ->and(MediaFolder::find($id)?->label)->toBe('Receipts');

    // Second call is idempotent — same id, no new row.
    $id2 = MediaFolders::idFor(MediaFolders::RECEIPTS);
    expect($id2)->toBe($id)
        ->and(MediaFolder::where('path', 'receipts')->count())->toBe(1);
});

it('inventory captures land in the Inventory folder', function () {
    authedInHousehold();
    Queue::fake();

    Livewire::test('mobile.capture-inventory')
        ->set('photo', UploadedFile::fake()->image('item.jpg')->size(50))
        ->call('save', false);

    $media = Media::first();
    expect($media)->not->toBeNull()
        ->and($media->folder_id)->toBe(MediaFolders::idFor(MediaFolders::INVENTORY));
});

it('photo captures route by kind to the right folder', function () {
    authedInHousehold();
    Queue::fake();

    // Each kind → one photo → check the corresponding folder.
    $expectations = [
        'receipt' => MediaFolders::RECEIPTS,
        'bill' => MediaFolders::BILLS,
        'document' => MediaFolders::DOCUMENTS,
        'post' => MediaFolders::POST,
    ];

    foreach ($expectations as $kind => $slug) {
        Livewire::test('mobile.capture-photo', ['kind' => $kind])
            ->assertSet('kind', $kind)
            ->set('photo', UploadedFile::fake()->image("{$kind}.jpg")->size(50))
            ->call('save', false);

        $expectedFolderId = MediaFolders::idFor($slug);
        $latest = Media::orderByDesc('id')->first();
        expect($latest->folder_id)
            ->toBe($expectedFolderId, "kind={$kind} should route to {$slug}");
    }
});

it('rejects unknown kind values and keeps the default', function () {
    authedInHousehold();

    Livewire::test('mobile.capture-photo')
        ->assertSet('kind', 'receipt')
        ->call('setKind', 'malicious')
        ->assertSet('kind', 'receipt');
});

it('idFor returns null when no household is active', function () {
    CurrentHousehold::set(null);

    expect(MediaFolders::idFor(MediaFolders::RECEIPTS))->toBeNull();
});

it('idFor returns null for unknown slugs', function () {
    authedInHousehold();

    expect(MediaFolders::idFor('not-a-real-folder'))->toBeNull();
});

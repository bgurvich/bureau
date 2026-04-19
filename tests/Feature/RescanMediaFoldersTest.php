<?php

use App\Models\Media;
use App\Models\MediaFolder;
use Illuminate\Support\Facades\Storage;

it('creates Media rows for files inside registered folders (idempotent)', function () {
    Storage::fake('local');
    authedInHousehold();

    MediaFolder::create(['label' => 'Receipts', 'path' => 'scans/receipts', 'active' => true]);

    Storage::disk('local')->put('scans/receipts/2026-01.jpg', 'fake');
    Storage::disk('local')->put('scans/receipts/2026-02.jpg', 'fake');

    $this->artisan('media:rescan')->assertSuccessful();

    expect(Media::count())->toBe(2)
        ->and(Media::pluck('path')->sort()->values()->all())
        ->toBe(['scans/receipts/2026-01.jpg', 'scans/receipts/2026-02.jpg'])
        ->and(Media::first()->ocr_status)->toBe('pending');  // images queued

    // Re-running finds nothing new.
    $this->artisan('media:rescan')->assertSuccessful();
    expect(Media::count())->toBe(2);
});

it('skips inactive folders and updates last_scanned_at on active ones', function () {
    Storage::fake('local');
    authedInHousehold();

    $active = MediaFolder::create(['label' => 'A', 'path' => 'a', 'active' => true]);
    $inactive = MediaFolder::create(['label' => 'B', 'path' => 'b', 'active' => false]);

    Storage::disk('local')->put('a/a.txt', 'x');
    Storage::disk('local')->put('b/b.txt', 'x');

    $this->artisan('media:rescan')->assertSuccessful();

    expect(Media::count())->toBe(1)
        ->and(Media::first()->path)->toBe('a/a.txt')
        ->and($active->fresh()->last_scanned_at)->not->toBeNull()
        ->and($inactive->fresh()->last_scanned_at)->toBeNull();
});

it('warns and skips when a folder path does not exist on disk', function () {
    Storage::fake('local');
    authedInHousehold();

    MediaFolder::create(['label' => 'Gone', 'path' => 'missing/path', 'active' => true]);

    $this->artisan('media:rescan')
        ->expectsOutputToContain('directory missing')
        ->assertSuccessful();

    expect(Media::count())->toBe(0);
});

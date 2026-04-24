<?php

declare(strict_types=1);

use App\Models\Media;
use App\Support\MediaPlaceholder;
use Illuminate\Support\Facades\Storage;

it('writes png bytes when the media mime is image/*', function () {
    authedInHousehold();
    Storage::fake('local');
    $media = Media::create([
        'disk' => 'local', 'path' => 'stubs/img.png',
        'original_name' => 'img.png', 'mime' => 'image/png', 'size' => 0,
    ]);

    expect(MediaPlaceholder::ensureStub($media))->toBeTrue();
    expect(Storage::disk('local')->exists('stubs/img.png'))->toBeTrue();
    expect(Storage::disk('local')->get('stubs/img.png'))->toStartWith("\x89PNG");
});

it('writes pdf bytes when the media mime is not an image', function () {
    authedInHousehold();
    Storage::fake('local');
    $media = Media::create([
        'disk' => 'local', 'path' => 'stubs/doc.pdf',
        'original_name' => 'doc.pdf', 'mime' => 'application/pdf', 'size' => 0,
    ]);

    MediaPlaceholder::ensureStub($media);

    expect(Storage::disk('local')->get('stubs/doc.pdf'))->toStartWith('%PDF-');
});

it('is a no-op when the file already exists', function () {
    authedInHousehold();
    Storage::fake('local');
    Storage::disk('local')->put('stubs/img.png', 'existing-bytes');
    $media = Media::create([
        'disk' => 'local', 'path' => 'stubs/img.png',
        'original_name' => 'img.png', 'mime' => 'image/png', 'size' => 0,
    ]);

    expect(MediaPlaceholder::ensureStub($media))->toBeFalse();
    expect(Storage::disk('local')->get('stubs/img.png'))->toBe('existing-bytes');
});

it('repairAll returns the count of files written', function () {
    authedInHousehold();
    Storage::fake('local');
    Media::create(['disk' => 'local', 'path' => 'a.png', 'original_name' => 'a.png', 'mime' => 'image/png', 'size' => 0]);
    Media::create(['disk' => 'local', 'path' => 'b.pdf', 'original_name' => 'b.pdf', 'mime' => 'application/pdf', 'size' => 0]);
    Storage::disk('local')->put('c.png', 'existing');
    Media::create(['disk' => 'local', 'path' => 'c.png', 'original_name' => 'c.png', 'mime' => 'image/png', 'size' => 0]);

    expect(MediaPlaceholder::repairAll())->toBe(2);
});

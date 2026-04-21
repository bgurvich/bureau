<?php

declare(strict_types=1);

use App\Models\Media;
use Illuminate\Support\Facades\Storage;

it('returns 404 when the media has no generated thumbnail yet', function () {
    authedInHousehold();
    Storage::fake('local');

    $m = Media::create([
        'disk' => 'local', 'source' => 'upload',
        'path' => 'uploads/bill.pdf', 'original_name' => 'bill.pdf',
        'mime' => 'application/pdf', 'size' => 4200,
    ]);

    $this->get(route('media.thumb', $m))->assertNotFound();
});

it('streams the generated thumbnail PNG with aggressive cache headers', function () {
    authedInHousehold();
    Storage::fake('local');
    Storage::disk('local')->put('thumbs/1.png', 'fake-png-bytes');

    $m = Media::create([
        'disk' => 'local', 'source' => 'upload',
        'path' => 'uploads/bill.pdf', 'original_name' => 'bill.pdf',
        'mime' => 'application/pdf', 'size' => 4200,
        'thumb_path' => 'thumbs/1.png',
    ]);

    $response = $this->get(route('media.thumb', $m))->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=86400');
});

it('blocks unauthenticated thumbnail access', function () {
    // Create the media while authenticated (household_id is required), then
    // clear the session and hit the route — the auth middleware must redirect.
    authedInHousehold();
    Storage::fake('local');
    Storage::disk('local')->put('thumbs/7.png', 'x');

    $m = Media::create([
        'disk' => 'local', 'source' => 'upload',
        'path' => 'uploads/x.pdf', 'original_name' => 'x.pdf',
        'mime' => 'application/pdf', 'size' => 1,
        'thumb_path' => 'thumbs/7.png',
    ]);

    auth()->logout();
    session()->flush();

    $this->get(route('media.thumb', $m))->assertRedirect(route('login'));
});

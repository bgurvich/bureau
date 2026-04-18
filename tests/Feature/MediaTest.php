<?php

use App\Models\Household;
use App\Models\Media;
use App\Support\CurrentHousehold;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('opens preview, saves metadata, and deletes media from the library', function () {
    Storage::fake('local');
    authedInHousehold();

    Storage::disk('local')->put('media/keep.jpg', 'fake');
    $m = Media::create([
        'disk' => 'local',
        'path' => 'media/keep.jpg',
        'original_name' => 'keep.jpg',
        'mime' => 'image/jpeg',
        'size' => 4,
        'ocr_status' => 'skip',
    ]);

    $c = Livewire::test('media-index')
        ->call('openPreview', $m->id)
        ->assertSet('previewId', $m->id)
        ->assertSet('draft_original_name', 'keep.jpg')
        ->set('draft_original_name', 'renamed.jpg')
        ->set('draft_tag_list', '#receipt taxes')
        ->call('saveMetadata');

    $fresh = $m->fresh();
    expect($fresh->original_name)->toBe('renamed.jpg')
        ->and($fresh->tags()->pluck('name')->all())->toContain('receipt')
        ->and($fresh->tags()->pluck('name')->all())->toContain('taxes');

    $c->call('deleteMedia')->assertSet('previewId', null);
    expect(Media::find($m->id))->toBeNull();
    expect(Storage::disk('local')->exists('media/keep.jpg'))->toBeFalse();
});

it('renders the Media drill-down', function () {
    authedInHousehold();

    Media::create([
        'disk' => 'local',
        'path' => 'stub.jpg',
        'original_name' => 'receipt.jpg',
        'mime' => 'image/jpeg',
        'size' => 123456,
        'captured_at' => now(),
    ]);

    $this->get('/media')
        ->assertOk()
        ->assertSee('receipt.jpg');
});

it('filters media by mime', function () {
    authedInHousehold();

    Media::create(['disk' => 'local', 'path' => 'scan.pdf', 'original_name' => 'Tax return 2025.pdf', 'mime' => 'application/pdf', 'size' => 500000]);
    Media::create(['disk' => 'local', 'path' => 'photo.jpg', 'original_name' => 'Vacation.jpg', 'mime' => 'image/jpeg', 'size' => 1200000]);

    $this->get('/media?mime=pdf')
        ->assertSee('Tax return 2025.pdf')
        ->assertDontSee('Vacation.jpg');
});

it('streams a media file through the gated endpoint', function () {
    authedInHousehold();
    Storage::fake('local');
    $upload = UploadedFile::fake()->create('hello.txt', 4, 'text/plain');
    $path = $upload->storeAs('', 'hello.txt', 'local');

    $media = Media::create([
        'disk' => 'local',
        'path' => $path,
        'original_name' => 'hello.txt',
        'mime' => 'text/plain',
        'size' => $upload->getSize(),
    ]);

    $response = $this->get('/media/'.$media->id.'/file');
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/plain');
});

it('blocks unauthenticated media access', function () {
    Storage::fake('local');

    // Set up a media row without authenticating.
    $household = Household::create(['name' => 'Outside', 'default_currency' => 'USD']);
    CurrentHousehold::set($household);
    $media = Media::create(['disk' => 'local', 'path' => 'secret.txt', 'original_name' => 'secret.txt', 'mime' => 'text/plain', 'size' => 10]);

    $this->get('/media/'.$media->id.'/file')->assertRedirect('/login');
});

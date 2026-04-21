<?php

use App\Jobs\OcrMedia;
use App\Models\Household;
use App\Models\Media;
use App\Support\CurrentHousehold;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
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

it('runs Tesseract on a pending Media and stores the extracted text', function () {
    Storage::fake('local');
    authedInHousehold();

    Storage::disk('local')->put('scans/receipt.jpg', 'fake');
    $m = Media::create([
        'disk' => 'local', 'path' => 'scans/receipt.jpg',
        'original_name' => 'receipt.jpg', 'mime' => 'image/jpeg', 'size' => 4,
        'ocr_status' => 'pending',
    ]);

    Process::fake(fn () => Process::result(output: "Total \$42.00\nVisa **** 1234"));

    (new OcrMedia($m->id))->handle();

    $fresh = $m->fresh();
    expect($fresh->ocr_status)->toBe('done')
        ->and($fresh->ocr_text)->toContain('Total $42.00');
});

it('marks OCR as failed when the tesseract binary errors', function () {
    Storage::fake('local');
    authedInHousehold();

    Storage::disk('local')->put('bad.jpg', 'fake');
    $m = Media::create([
        'disk' => 'local', 'path' => 'bad.jpg',
        'original_name' => 'bad.jpg', 'mime' => 'image/jpeg', 'size' => 4,
        'ocr_status' => 'pending',
    ]);

    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'cannot read'));

    (new OcrMedia($m->id))->handle();

    expect($m->fresh()->ocr_status)->toBe('failed');
});

it('skips OCR for non-image, non-pdf media', function () {
    authedInHousehold();

    $m = Media::create([
        'disk' => 'local', 'path' => 'doc.zip',
        'original_name' => 'doc.zip', 'mime' => 'application/zip', 'size' => 100,
        'ocr_status' => 'pending',
    ]);

    (new OcrMedia($m->id))->handle();

    expect($m->fresh()->ocr_status)->toBe('skip');
});

it('retries OCR from the Media preview modal', function () {
    Storage::fake('local');
    authedInHousehold();

    Storage::disk('local')->put('r.jpg', 'fake');
    $m = Media::create([
        'disk' => 'local', 'path' => 'r.jpg',
        'original_name' => 'r.jpg', 'mime' => 'image/jpeg', 'size' => 4,
        'ocr_status' => 'failed',
    ]);

    Process::fake(fn () => Process::result(output: 'RETRY TEXT'));

    Livewire::test('media-index')
        ->call('openPreview', $m->id)
        ->call('retryOcr');

    $fresh = $m->fresh();
    expect($fresh->ocr_status)->toBe('done')
        ->and($fresh->ocr_text)->toBe('RETRY TEXT');
});

it('surfaces OCR text hits in global search and deep-links back to the media preview', function () {
    authedInHousehold();

    $m = Media::create([
        'disk' => 'local', 'path' => 'bill.jpg',
        'original_name' => 'bill.jpg', 'mime' => 'image/jpeg', 'size' => 1,
        'ocr_status' => 'done',
        'ocr_text' => 'Acme Energy statement — account #9921 — total $142.03',
    ]);

    $c = Livewire::test('global-search')
        ->call('openSearch')
        ->set('query', 'Acme Energy');

    $results = $c->get('results');
    $hit = collect($results)->firstWhere('group', __('Media'));
    expect($hit)->not->toBeNull()
        ->and($hit['subtitle'])->toContain('Acme Energy');

    // Deep link to /media?focus=<id> auto-opens the preview modal
    Livewire::withQueryParams(['focus' => $m->id])
        ->test('media-index')
        ->assertSet('previewId', $m->id);
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

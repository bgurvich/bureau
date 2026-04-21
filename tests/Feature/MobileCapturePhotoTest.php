<?php

use App\Jobs\OcrMedia;
use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

it('renders the photo capture page for an authed user', function () {
    authedInHousehold();

    // The page merged with /capture/post into a single unified
    // surface; the header is now "Capture" with a kind picker.
    $this->get(route('mobile.capture.photo'))
        ->assertOk()
        ->assertSee(__('Capture'))
        ->assertSee(__('Receipt'))
        ->assertSee(__('Tap to capture'), false);
});

it('saves a captured photo as Media and dispatches OCR', function () {
    authedInHousehold();

    Livewire::test('mobile.capture-photo')
        ->set('photo', UploadedFile::fake()->image('receipt.jpg'))
        ->call('save', true)
        ->assertSet('savedCount', 1)
        ->assertSet('photo', null);

    $media = Media::firstOrFail();
    expect($media->ocr_status)->toBe('pending')
        ->and($media->disk)->toBe('local')
        ->and($media->mime)->toBe('image/jpeg')
        ->and(Storage::disk('local')->exists($media->path))->toBeTrue();

    Queue::assertPushed(OcrMedia::class, fn ($job) => $job->mediaId === $media->id);
});

it('loops on Save & next for bulk scanning', function () {
    authedInHousehold();

    Livewire::test('mobile.capture-photo')
        ->set('photo', UploadedFile::fake()->image('a.jpg'))
        ->call('save', true)
        ->set('photo', UploadedFile::fake()->image('b.jpg'))
        ->call('save', true)
        ->assertSet('savedCount', 2);

    expect(Media::count())->toBe(2);
});

it('rejects non-image uploads', function () {
    authedInHousehold();

    Livewire::test('mobile.capture-photo')
        ->set('photo', UploadedFile::fake()->create('bad.txt', 100, 'text/plain'))
        ->call('save', true)
        ->assertHasErrors(['photo']);

    expect(Media::count())->toBe(0);
});

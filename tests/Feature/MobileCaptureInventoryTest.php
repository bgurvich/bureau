<?php

use App\Models\InventoryItem;
use App\Models\Media;
use App\Models\Property;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

it('applies sticky property/room/container to every photo captured in the session', function () {
    authedInHousehold();
    $property = Property::create(['kind' => 'home', 'name' => 'Our house']);

    $c = Livewire::test('mobile.capture-inventory')
        ->set('stickyProperty', $property->id)
        ->set('stickyRoom', 'Garage')
        ->set('stickyContainer', 'Shelf A');

    $c->set('photo', UploadedFile::fake()->image('a.jpg', 800, 600))->call('save', true);
    $c->set('photo', UploadedFile::fake()->image('b.jpg', 800, 600))->call('save', true);

    $items = InventoryItem::orderBy('id')->get();
    expect($items)->toHaveCount(2);
    foreach ($items as $i) {
        expect($i->location_property_id)->toBe($property->id)
            ->and($i->room)->toBe('Garage')
            ->and($i->container)->toBe('Shelf A');
    }
});

it('clearLocation wipes sticky location fields', function () {
    authedInHousehold();
    $property = Property::create(['kind' => 'home', 'name' => 'Our house']);

    Livewire::test('mobile.capture-inventory')
        ->set('stickyProperty', $property->id)
        ->set('stickyRoom', 'Kitchen')
        ->set('stickyContainer', 'Drawer')
        ->call('clearLocation')
        ->assertSet('stickyProperty', null)
        ->assertSet('stickyRoom', '')
        ->assertSet('stickyContainer', '');
});

it('renders the capture-inventory page for an authed user', function () {
    authedInHousehold();

    $this->get(route('mobile.capture.inventory'))
        ->assertOk()
        ->assertSee(__('Photo inventory'))
        ->assertSee(__('Tap to take a photo'));
});

it('saves a captured photo as an unprocessed inventory item with media attached', function () {
    authedInHousehold();
    $photo = UploadedFile::fake()->image('couch.jpg', 1024, 768);

    Livewire::test('mobile.capture-inventory')
        ->set('photo', $photo)
        ->call('save', true)
        ->assertSet('savedCount', 1)
        ->assertSet('photo', null);

    $item = InventoryItem::firstOrFail();
    expect($item->processed_at)->toBeNull()
        ->and($item->name)->toStartWith(__('Captured '));

    $media = Media::firstOrFail();
    expect($media->ocr_status)->toBe('skip')
        ->and($media->disk)->toBe('local')
        ->and(Storage::disk('local')->exists($media->path))->toBeTrue();

    expect($item->media()->count())->toBe(1)
        ->and($item->media()->first()->pivot->role)->toBe('photo');
});

it('keeps the user on the page when Save & next is used for bulk capture', function () {
    authedInHousehold();

    Livewire::test('mobile.capture-inventory')
        ->set('photo', UploadedFile::fake()->image('a.jpg'))
        ->call('save', true)
        ->set('photo', UploadedFile::fake()->image('b.jpg'))
        ->call('save', true)
        ->set('photo', UploadedFile::fake()->image('c.jpg'))
        ->call('save', true)
        ->assertSet('savedCount', 3);

    expect(InventoryItem::count())->toBe(3);
    expect(Media::count())->toBe(3);
});

it('rejects non-image uploads', function () {
    authedInHousehold();

    Livewire::test('mobile.capture-inventory')
        ->set('photo', UploadedFile::fake()->create('bad.txt', 100, 'text/plain'))
        ->call('save', true)
        ->assertHasErrors(['photo']);

    expect(InventoryItem::count())->toBe(0);
});

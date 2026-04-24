<?php

declare(strict_types=1);

use App\Models\InventoryItem;
use App\Models\Media;
use Livewire\Livewire;

it('opens on media-library-open with a target type + id', function () {
    authedInHousehold();
    $item = InventoryItem::create(['name' => 'Monitor']);

    Livewire::test('media-library-modal')
        ->assertSet('open', false)
        ->dispatch('media-library-open', type: 'inventory', id: $item->id, role: 'photo')
        ->assertSet('open', true)
        ->assertSet('targetType', 'inventory')
        ->assertSet('targetId', $item->id);
});

it('refuses to open for an unknown target type', function () {
    authedInHousehold();

    Livewire::test('media-library-modal')
        ->dispatch('media-library-open', type: 'fake-thing', id: 1)
        ->assertSet('open', false);
});

it('toggleSelect adds and removes ids', function () {
    authedInHousehold();

    Livewire::test('media-library-modal')
        ->call('toggleSelect', 5)
        ->assertSet('selectedIds', [5])
        ->call('toggleSelect', 9)
        ->assertSet('selectedIds', [5, 9])
        ->call('toggleSelect', 5)
        ->assertSet('selectedIds', [9]);
});

it('attachSelected attaches media to the target via the mediables pivot', function () {
    authedInHousehold();
    $item = InventoryItem::create(['name' => 'Monitor']);
    $a = Media::create(['disk' => 'local', 'path' => 'seed/a.jpg', 'original_name' => 'a.jpg', 'mime' => 'image/jpeg', 'size' => 100]);
    $b = Media::create(['disk' => 'local', 'path' => 'seed/b.jpg', 'original_name' => 'b.jpg', 'mime' => 'image/jpeg', 'size' => 200]);

    Livewire::test('media-library-modal')
        ->dispatch('media-library-open', type: 'inventory', id: $item->id)
        ->set('selectedIds', [$a->id, $b->id])
        ->call('attachSelected');

    expect($item->media()->count())->toBe(2);
    expect($item->media()->pluck('media.id')->all())->toContain($a->id, $b->id);
});

it('attachSelected preserves existing attachments via syncWithoutDetaching', function () {
    authedInHousehold();
    $item = InventoryItem::create(['name' => 'Monitor']);
    $a = Media::create(['disk' => 'local', 'path' => 'seed/a.jpg', 'original_name' => 'a.jpg', 'mime' => 'image/jpeg', 'size' => 100]);
    $b = Media::create(['disk' => 'local', 'path' => 'seed/b.jpg', 'original_name' => 'b.jpg', 'mime' => 'image/jpeg', 'size' => 200]);
    $item->media()->attach($a->id, ['role' => 'photo', 'position' => 0]);

    Livewire::test('media-library-modal')
        ->dispatch('media-library-open', type: 'inventory', id: $item->id)
        ->set('selectedIds', [$b->id])
        ->call('attachSelected');

    expect($item->media()->count())->toBe(2);
});

it('library filters by filename search', function () {
    authedInHousehold();
    Media::create(['disk' => 'local', 'path' => 'a.jpg', 'original_name' => 'vacation.jpg', 'mime' => 'image/jpeg', 'size' => 10]);
    Media::create(['disk' => 'local', 'path' => 'b.jpg', 'original_name' => 'receipt-jan.pdf', 'mime' => 'application/pdf', 'size' => 10]);

    $lib = Livewire::test('media-library-modal')
        ->set('open', true)
        ->set('search', 'vacation')
        ->get('library');

    expect($lib)->toHaveCount(1);
    expect($lib->first()->original_name)->toBe('vacation.jpg');
});

it('library filters by mime prefix', function () {
    authedInHousehold();
    Media::create(['disk' => 'local', 'path' => 'a.jpg', 'original_name' => 'a.jpg', 'mime' => 'image/jpeg', 'size' => 10]);
    Media::create(['disk' => 'local', 'path' => 'b.pdf', 'original_name' => 'b.pdf', 'mime' => 'application/pdf', 'size' => 10]);

    $lib = Livewire::test('media-library-modal')
        ->set('open', true)
        ->set('mimeFilter', 'application/pdf')
        ->get('library');
    expect($lib->pluck('original_name')->all())->toBe(['b.pdf']);
});

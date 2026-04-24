<?php

declare(strict_types=1);

use App\Models\InventoryItem;
use App\Models\Media;
use Livewire\Livewire;

it('detaches session-attached media when inspector closes without save', function () {
    authedInHousehold();
    $item = InventoryItem::create(['name' => 'Monitor']);
    $a = Media::create(['disk' => 'local', 'path' => 'a.jpg', 'original_name' => 'a.jpg', 'mime' => 'image/jpeg', 'size' => 10]);
    $b = Media::create(['disk' => 'local', 'path' => 'b.jpg', 'original_name' => 'b.jpg', 'mime' => 'image/jpeg', 'size' => 10]);
    $item->media()->attach($a->id, ['role' => 'photo', 'position' => 0]);
    $item->media()->attach($b->id, ['role' => 'photo', 'position' => 1]);

    Livewire::test('inspector')
        ->dispatch('inspector-open', type: 'inventory', id: $item->id)
        ->dispatch('media-attached', type: 'inventory', id: $item->id, mediaIds: [$a->id, $b->id])
        ->call('close');

    expect($item->fresh()->media()->count())->toBe(0);
});

it('preserves session-attached media when inspector closes via save', function () {
    authedInHousehold();
    $item = InventoryItem::create(['name' => 'Monitor']);
    $a = Media::create(['disk' => 'local', 'path' => 'a.jpg', 'original_name' => 'a.jpg', 'mime' => 'image/jpeg', 'size' => 10]);
    $item->media()->attach($a->id, ['role' => 'photo', 'position' => 0]);

    Livewire::test('inspector')
        ->dispatch('inspector-open', type: 'inventory', id: $item->id)
        ->dispatch('media-attached', type: 'inventory', id: $item->id, mediaIds: [$a->id])
        ->dispatch('inspector-form-saved', type: 'inventory', id: $item->id);

    expect($item->fresh()->media()->count())->toBe(1);
});

it('leaves pre-existing attachments alone on close', function () {
    authedInHousehold();
    $item = InventoryItem::create(['name' => 'Monitor']);
    $a = Media::create(['disk' => 'local', 'path' => 'a.jpg', 'original_name' => 'a.jpg', 'mime' => 'image/jpeg', 'size' => 10]);
    $item->media()->attach($a->id, ['role' => 'photo', 'position' => 0]);

    Livewire::test('inspector')
        ->dispatch('inspector-open', type: 'inventory', id: $item->id)
        ->call('close');

    // No session-attached ids, nothing should get detached.
    expect($item->fresh()->media()->count())->toBe(1);
});

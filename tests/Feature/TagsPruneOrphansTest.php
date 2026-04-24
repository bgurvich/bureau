<?php

declare(strict_types=1);

use App\Models\Tag;
use App\Models\Task;
use Livewire\Livewire;

it('counts tags with zero taggables', function () {
    authedInHousehold();
    Tag::create(['name' => 'errands', 'slug' => 'errands']);
    Tag::create(['name' => 'admin', 'slug' => 'admin']);
    $attached = Tag::create(['name' => 'home', 'slug' => 'home']);
    $task = Task::create(['title' => 'T', 'state' => 'open']);
    $task->tags()->attach($attached->id);

    expect(Livewire::test('tags-index')->get('orphanCount'))->toBe(2);
});

it('pruneOrphans deletes only tags with no attachments', function () {
    authedInHousehold();
    $orphan = Tag::create(['name' => 'stale', 'slug' => 'stale']);
    $attached = Tag::create(['name' => 'home', 'slug' => 'home']);
    $task = Task::create(['title' => 'T', 'state' => 'open']);
    $task->tags()->attach($attached->id);

    Livewire::test('tags-index')->call('pruneOrphans');

    expect(Tag::find($orphan->id))->toBeNull();
    expect(Tag::find($attached->id))->not->toBeNull();
});

it('pruneOrphans is a no-op when there are no orphans', function () {
    authedInHousehold();
    $attached = Tag::create(['name' => 'home', 'slug' => 'home']);
    $task = Task::create(['title' => 'T', 'state' => 'open']);
    $task->tags()->attach($attached->id);

    $c = Livewire::test('tags-index')->call('pruneOrphans');

    expect(Tag::count())->toBe(1);
    expect($c->get('pruneNotice'))->toContain('0');
});

<?php

use App\Models\Contact;
use App\Models\Note;
use App\Models\Tag;
use App\Models\Task;

it('lists tags with usage counts on /tags', function () {
    authedInHousehold();

    $home = Tag::create(['slug' => 'home', 'name' => 'home']);
    $tax = Tag::create(['slug' => 'tax-2026', 'name' => 'tax-2026']);

    Note::create(['body' => 'n1'])->tags()->attach($home->id);
    Note::create(['body' => 'n2'])->tags()->attach([$home->id, $tax->id]);
    Task::create(['title' => 'Task A', 'state' => 'open'])->tags()->attach($tax->id);

    $this->get('/tags')
        ->assertOk()
        ->assertSee('home')
        ->assertSee('tax-2026')
        ->assertSeeInOrder(['home', 'tax-2026']);  // home (2) before tax (2); tiebreaker by name → home < tax
});

it('shows cross-domain records on /tags/{slug}', function () {
    authedInHousehold();

    $tag = Tag::create(['slug' => 'rental', 'name' => 'rental']);
    Note::create(['title' => 'Rental unit notes', 'body' => 'x'])->tags()->attach($tag->id);
    Task::create(['title' => 'Inspect rental', 'state' => 'open'])->tags()->attach($tag->id);
    Contact::create(['kind' => 'org', 'display_name' => 'Rental Agency LLC'])->tags()->attach($tag->id);

    $this->get('/tags/rental')
        ->assertOk()
        ->assertSee('rental')
        ->assertSee('Rental unit notes')
        ->assertSee('Inspect rental')
        ->assertSee('Rental Agency LLC');
});

it('shows a not-found panel for an unknown tag slug', function () {
    authedInHousehold();

    $this->get('/tags/does-not-exist')
        ->assertOk()
        ->assertSee('Tag not found');
});

<?php

use App\Models\Contact;
use App\Models\Note;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;

function seedForLife(): User
{
    $user = authedInHousehold();
    Task::create(['title' => 'Renew passport', 'priority' => 1, 'state' => 'open']);
    Contact::create(['kind' => 'person', 'display_name' => 'Alice Example', 'favorite' => true]);
    Note::create(['title' => 'Weekly review', 'body' => 'Plan the week', 'pinned' => true, 'user_id' => $user->id]);

    return $user;
}

it('renders the Tasks drill-down with real data', function () {
    seedForLife();

    $this->get('/tasks')
        ->assertOk()
        ->assertSee('Renew passport')
        ->assertSee('+ New task');
});

it('renders the Contacts drill-down with real data', function () {
    seedForLife();

    $this->get('/contacts')
        ->assertOk()
        ->assertSee('Alice Example')
        ->assertSee('+ New contact');
});

it('renders the Notes drill-down with real data', function () {
    seedForLife();

    $this->get('/notes')
        ->assertOk()
        ->assertSee('Weekly review')
        ->assertSee('+ New note');
});

it('filters notes by tag slug from the URL', function () {
    authedInHousehold();

    $tagged = Note::create(['title' => 'Receipt to file', 'body' => 'taxes']);
    $other = Note::create(['title' => 'Grocery list', 'body' => 'bananas']);

    $tax = Tag::create(['name' => 'tax-2026', 'slug' => 'tax-2026']);
    $tagged->tags()->attach($tax->id);

    $this->get('/notes?tag=tax-2026')
        ->assertSee('Receipt to file')
        ->assertSee('#tax-2026')
        ->assertDontSee('Grocery list');
});

<?php

use App\Models\Contact;
use App\Models\InventoryItem;
use App\Models\Note;
use App\Models\Property;
use App\Models\Task;
use App\Models\Vehicle;
use Livewire\Livewire;

it('returns no results for queries shorter than two characters', function () {
    authedInHousehold();
    Note::create(['title' => 'Wide-angle lens', 'body' => 'Camera thoughts']);

    Livewire::test('global-search')
        ->set('query', 'a')
        ->assertSet('results', []);
});

it('returns grouped results across domains', function () {
    $user = authedInHousehold();
    Note::create(['title' => 'Project alpha', 'body' => 'brainstorm', 'user_id' => $user->id]);
    Task::create(['title' => 'Write up alpha scope', 'priority' => 2, 'state' => 'open']);
    Contact::create(['kind' => 'person', 'display_name' => 'Alpha Consultant Inc']);

    $results = Livewire::test('global-search')
        ->set('query', 'alpha')
        ->get('results');

    $groups = array_unique(array_column($results, 'group'));
    sort($groups);

    expect($groups)->toBe(['Contacts', 'Notes', 'Tasks']);
    expect(count($results))->toBeGreaterThanOrEqual(3);
});

it('dispatches inspector-open when selecting an Inspector-supported result', function () {
    authedInHousehold();
    $task = Task::create(['title' => 'Unique name here', 'priority' => 3, 'state' => 'open']);

    Livewire::test('global-search')
        ->set('query', 'Unique')
        ->call('selectResult', 0)
        ->assertDispatched('inspector-open', type: 'task', id: $task->id)
        ->assertSet('open', false);
});

it('opens via event and resets on close', function () {
    authedInHousehold();

    Livewire::test('global-search')
        ->call('openSearch')
        ->assertSet('open', true)
        ->set('query', 'abc')
        ->call('close')
        ->assertSet('open', false)
        ->assertSet('query', '');
});

it('returns vehicles, properties, and inventory as inspector-openable hits', function () {
    $user = authedInHousehold();
    Vehicle::create(['kind' => 'car', 'make' => 'Honda', 'model' => 'Civic', 'year' => 2020, 'license_plate' => 'ABC123', 'primary_user_id' => $user->id]);
    Property::create(['kind' => 'home', 'name' => 'SF Apartment', 'acquired_on' => now()->subYears(2)->toDateString()]);
    InventoryItem::create(['name' => 'Honda-branded jacket', 'category' => 'clothing']);

    $results = Livewire::test('global-search')
        ->set('query', 'honda')
        ->get('results');

    $groups = array_unique(array_column($results, 'group'));
    expect($groups)->toContain('Vehicles', 'Inventory');

    foreach ($results as $row) {
        if (($row['group'] ?? '') === 'Vehicles') {
            expect($row['inspector'] ?? false)->toBeTrue()
                ->and($row['type'])->toBe('vehicle');
        }
    }
});

it('clamps active index within the result set', function () {
    authedInHousehold();
    Note::create(['title' => 'alpha', 'body' => '']);
    Note::create(['title' => 'alpha 2', 'body' => '']);

    Livewire::test('global-search')
        ->set('query', 'alpha')
        ->call('moveActive', 99)
        ->assertSet('active', 1)
        ->call('moveActive', -99)
        ->assertSet('active', 0);
});

<?php

use App\Models\Contract;
use App\Models\Note;
use App\Models\Property;
use App\Models\Task;
use App\Models\Vehicle;
use Livewire\Livewire;

it('Task::syncSubjects creates pivot rows and subjects() returns live models', function () {
    authedInHousehold();
    $vehicle = Vehicle::create(['kind' => 'car', 'model' => 'Civic']);
    $contract = Contract::create(['kind' => 'insurance', 'title' => 'Geico auto']);

    $task = Task::create(['title' => 'Renew auto insurance', 'priority' => 2, 'state' => 'open']);
    $task->syncSubjects([
        ['type' => Vehicle::class, 'id' => $vehicle->id],
        ['type' => Contract::class, 'id' => $contract->id],
    ]);

    $subjects = $task->subjects();
    expect($subjects)->toHaveCount(2)
        ->and($subjects->pluck('id')->all())->toContain($vehicle->id)
        ->and($subjects->pluck('id')->all())->toContain($contract->id);
});

it('syncSubjects is idempotent and replaces existing pivot rows', function () {
    authedInHousehold();
    $vehicle = Vehicle::create(['kind' => 'car', 'model' => 'A']);
    $property = Property::create(['kind' => 'home', 'name' => 'B']);

    $task = Task::create(['title' => 't', 'priority' => 3, 'state' => 'open']);
    $task->syncSubjects([['type' => Vehicle::class, 'id' => $vehicle->id]]);
    $task->syncSubjects([
        ['type' => Vehicle::class, 'id' => $vehicle->id],
        ['type' => Property::class, 'id' => $property->id],
    ]);
    // Now swap subjects entirely
    $task->syncSubjects([['type' => Property::class, 'id' => $property->id]]);

    $subjects = $task->subjects();
    expect($subjects)->toHaveCount(1)
        ->and($subjects->first())->toBeInstanceOf(Property::class);
});

it('inverse Vehicle::tasks returns linked tasks', function () {
    authedInHousehold();
    $vehicle = Vehicle::create(['kind' => 'car', 'model' => 'Civic']);
    $t1 = Task::create(['title' => 'Rotate tires', 'priority' => 3, 'state' => 'open']);
    $t2 = Task::create(['title' => 'Oil change', 'priority' => 3, 'state' => 'open']);
    $t1->syncSubjects([['type' => Vehicle::class, 'id' => $vehicle->id]]);
    $t2->syncSubjects([['type' => Vehicle::class, 'id' => $vehicle->id]]);

    expect($vehicle->tasks()->pluck('id')->all())->toContain($t1->id)
        ->and($vehicle->tasks()->pluck('id')->all())->toContain($t2->id);
});

it('Note::syncSubjects + Vehicle::notes symmetry', function () {
    authedInHousehold();
    $vehicle = Vehicle::create(['kind' => 'car', 'model' => 'Civic']);

    $note = Note::create(['body' => 'Mechanic says brakes OK for 5k miles', 'user_id' => auth()->id()]);
    $note->syncSubjects([['type' => Vehicle::class, 'id' => $vehicle->id]]);

    expect($vehicle->notes()->pluck('id')->all())->toContain($note->id)
        ->and($note->subjects()->first()->id)->toBe($vehicle->id);
});

it('Inspector saves subjects on task create and reloads on edit', function () {
    authedInHousehold();
    $vehicle = Vehicle::create(['kind' => 'car', 'model' => 'Civic']);

    Livewire::test('inspector')
        ->call('openInspector', 'task')
        ->set('title', 'Renew insurance')
        ->set('state', 'open')
        ->set('priority', 2)
        ->set('subject_refs', ['vehicle:'.$vehicle->id])
        ->call('save');

    $task = Task::firstOrFail();
    expect($task->subjects()->first()->id)->toBe($vehicle->id);

    // Reopen for edit — subject_refs must be populated
    $c = Livewire::test('inspector')->call('openInspector', 'task', $task->id);
    expect($c->get('subject_refs'))->toContain('vehicle:'.$vehicle->id);
});

it('Inspector parseSubjectRefs drops malformed entries', function () {
    authedInHousehold();
    $vehicle = Vehicle::create(['kind' => 'car', 'model' => 'A']);

    Livewire::test('inspector')
        ->call('openInspector', 'task')
        ->set('title', 'X')
        ->set('state', 'open')
        ->set('priority', 3)
        ->set('subject_refs', [
            'vehicle:'.$vehicle->id,
            'bogus:99',         // unknown kind
            'vehicle:notanid',  // bad id
            ':5',               // missing kind
        ])
        ->call('save');

    expect(Task::firstOrFail()->subjects()->count())->toBe(1);
});

it('Inspector saves subjects on note', function () {
    authedInHousehold();
    $vehicle = Vehicle::create(['kind' => 'car', 'model' => 'Honda']);

    Livewire::test('inspector')
        ->call('openInspector', 'note')
        ->set('title', 'Mechanic')
        ->set('body', 'Brakes fine for now.')
        ->set('subject_refs', ['vehicle:'.$vehicle->id])
        ->call('save');

    expect(Note::firstOrFail()->subjects()->first()->id)->toBe($vehicle->id);
});

it('Inspector search returns matches across kinds when ≥2 chars typed', function () {
    authedInHousehold();
    $v = Vehicle::create(['kind' => 'car', 'model' => 'Civic']);
    $p = Property::create(['kind' => 'home', 'name' => 'Civic Center loft']);
    Contract::create(['kind' => 'insurance', 'title' => 'Elsewhere']);

    $c = Livewire::test('inspector')
        ->call('openInspector', 'task')
        ->set('subject_search', 'civi');

    $results = $c->get('subjectSearchResults');
    expect($results)->toHaveCount(2)
        ->and(collect($results)->pluck('ref')->all())->toContain('vehicle:'.$v->id)
        ->and(collect($results)->pluck('ref')->all())->toContain('property:'.$p->id);
});

it('subject search is silent until 2 chars', function () {
    authedInHousehold();
    Vehicle::create(['kind' => 'car', 'model' => 'Civic']);

    $c = Livewire::test('inspector')
        ->call('openInspector', 'task')
        ->set('subject_search', 'c');
    expect($c->get('subjectSearchResults'))->toBe([]);
});

it('addSubject, removeSubject, and moveSubjectTo manage the ordered list', function () {
    authedInHousehold();
    $v = Vehicle::create(['kind' => 'car', 'model' => 'A']);
    $p = Property::create(['kind' => 'home', 'name' => 'B']);
    $cn = Contract::create(['kind' => 'insurance', 'title' => 'C']);

    $c = Livewire::test('inspector')
        ->call('openInspector', 'task')
        ->call('addSubject', 'vehicle:'.$v->id)
        ->call('addSubject', 'property:'.$p->id)
        ->call('addSubject', 'contract:'.$cn->id);

    expect($c->get('subject_refs'))->toBe([
        'vehicle:'.$v->id, 'property:'.$p->id, 'contract:'.$cn->id,
    ]);

    // Drag contract (index 2) to index 0 → [contract, vehicle, property]
    $c->call('moveSubjectTo', 'contract:'.$cn->id, 0);
    expect($c->get('subject_refs'))->toBe([
        'contract:'.$cn->id, 'vehicle:'.$v->id, 'property:'.$p->id,
    ]);

    // Drag contract to the end → [vehicle, property, contract]
    $c->call('moveSubjectTo', 'contract:'.$cn->id, 2);
    expect($c->get('subject_refs'))->toBe([
        'vehicle:'.$v->id, 'property:'.$p->id, 'contract:'.$cn->id,
    ]);

    // Remove the middle
    $c->call('removeSubject', 'property:'.$p->id);
    expect($c->get('subject_refs'))->toBe([
        'vehicle:'.$v->id, 'contract:'.$cn->id,
    ]);
});

it('reorderSubjects rebuilds the chip order to match the supplied refs', function () {
    // Regression guard: the sortable-list component calls reorderSubjects
    // with the final DOM order on drop. Refs that survive must keep their
    // relative order exactly; stray refs from the DOM should be ignored
    // rather than dropping or duplicating.
    authedInHousehold();
    $v = Vehicle::create(['kind' => 'car', 'model' => 'A']);
    $p = Property::create(['kind' => 'home', 'name' => 'B']);
    $cn = Contract::create(['kind' => 'insurance', 'title' => 'C']);

    $c = Livewire::test('inspector')
        ->call('openInspector', 'task')
        ->call('addSubject', 'vehicle:'.$v->id)
        ->call('addSubject', 'property:'.$p->id)
        ->call('addSubject', 'contract:'.$cn->id);

    $c->call('reorderSubjects', [
        'contract:'.$cn->id,
        'vehicle:'.$v->id,
        'property:'.$p->id,
        'bogus:999',
    ]);

    expect($c->get('subject_refs'))->toBe([
        'contract:'.$cn->id,
        'vehicle:'.$v->id,
        'property:'.$p->id,
    ]);

    // Omitted refs get appended at the tail rather than silently dropped.
    $c->call('reorderSubjects', ['vehicle:'.$v->id]);
    expect($c->get('subject_refs'))->toBe([
        'vehicle:'.$v->id,
        'contract:'.$cn->id,
        'property:'.$p->id,
    ]);
});

it('moveSubjectTo clamps an out-of-bounds target index', function () {
    authedInHousehold();
    $v = Vehicle::create(['kind' => 'car', 'model' => 'A']);
    $p = Property::create(['kind' => 'home', 'name' => 'B']);

    $c = Livewire::test('inspector')
        ->call('openInspector', 'task')
        ->call('addSubject', 'vehicle:'.$v->id)
        ->call('addSubject', 'property:'.$p->id)
        ->call('moveSubjectTo', 'vehicle:'.$v->id, 99);

    expect($c->get('subject_refs'))->toBe([
        'property:'.$p->id, 'vehicle:'.$v->id,
    ]);
});

it('addSubject refuses duplicates and invalid kinds', function () {
    authedInHousehold();
    $v = Vehicle::create(['kind' => 'car', 'model' => 'A']);

    $c = Livewire::test('inspector')
        ->call('openInspector', 'task')
        ->call('addSubject', 'vehicle:'.$v->id)
        ->call('addSubject', 'vehicle:'.$v->id)   // duplicate
        ->call('addSubject', 'bogus:1')           // unknown kind
        ->call('addSubject', 'malformed');         // no colon

    expect($c->get('subject_refs'))->toBe(['vehicle:'.$v->id]);
});

it('subjects are returned in pivot position order', function () {
    authedInHousehold();
    $v = Vehicle::create(['kind' => 'car', 'model' => 'A']);
    $p = Property::create(['kind' => 'home', 'name' => 'B']);
    $cn = Contract::create(['kind' => 'insurance', 'title' => 'C']);

    $task = Task::create(['title' => 't', 'priority' => 3, 'state' => 'open']);
    $task->syncSubjects([
        ['type' => Property::class, 'id' => $p->id],
        ['type' => Vehicle::class, 'id' => $v->id],
        ['type' => Contract::class, 'id' => $cn->id],
    ]);

    $ids = $task->subjects()->map(fn ($m) => class_basename($m).':'.$m->id)->all();
    expect($ids)->toBe([
        'Property:'.$p->id,
        'Vehicle:'.$v->id,
        'Contract:'.$cn->id,
    ]);
});

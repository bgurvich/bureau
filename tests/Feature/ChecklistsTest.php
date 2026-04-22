<?php

use App\Models\ChecklistRun;
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use Livewire\Livewire;

beforeEach(function () {
    authedInHousehold();
});

it('creates a checklist template with items via the inspector', function () {
    Livewire::test('inspector')
        ->call('openInspector', 'checklist_template')
        ->set('checklist_name', 'Morning routine')
        ->set('checklist_description', 'First thing after waking up')
        ->set('checklist_time_of_day', 'morning')
        ->set('checklist_recurrence_mode', 'daily')
        ->set('checklist_items', [
            'a' => ['key' => 'a', 'id' => null, 'label' => 'Brush teeth', 'active' => true],
            'b' => ['key' => 'b', 'id' => null, 'label' => '10-min journal', 'active' => true],
            'c' => ['key' => 'c', 'id' => null, 'label' => '', 'active' => true], // empty row dropped
        ])
        ->call('save')
        ->assertSet('open', false);

    $template = ChecklistTemplate::first();
    expect($template)->not->toBeNull()
        ->and($template->name)->toBe('Morning routine')
        ->and($template->time_of_day)->toBe('morning')
        ->and($template->rrule)->toBe('FREQ=DAILY')
        ->and($template->items()->count())->toBe(2)
        ->and($template->items->pluck('label')->all())->toBe(['Brush teeth', '10-min journal']);
});

it('persists a custom RRULE when recurrence_mode is custom', function () {
    Livewire::test('inspector')
        ->call('openInspector', 'checklist_template')
        ->set('checklist_name', 'MWF workout')
        ->set('checklist_recurrence_mode', 'custom')
        ->set('checklist_rrule', 'FREQ=WEEKLY;BYDAY=MO,WE,FR')
        ->call('save');

    expect(ChecklistTemplate::first()->rrule)->toBe('FREQ=WEEKLY;BYDAY=MO,WE,FR');
});

it('persists FREQ=DAILY;COUNT=1 when recurrence_mode is one_off', function () {
    Livewire::test('inspector')
        ->call('openInspector', 'checklist_template')
        ->set('checklist_name', 'Welcome-a-pet — first week')
        ->set('checklist_recurrence_mode', 'one_off')
        ->set('checklist_dtstart', '2026-06-01')
        ->call('save');

    $template = ChecklistTemplate::first();
    expect($template->rrule)->toBe('FREQ=DAILY;COUNT=1')
        ->and($template->dtstart->toDateString())->toBe('2026-06-01');
});

it('reopening a one-off checklist restores recurrence_mode = one_off (not custom)', function () {
    $template = ChecklistTemplate::create([
        'name' => 'Onboarding step 1',
        'time_of_day' => 'anytime',
        'rrule' => 'FREQ=DAILY;COUNT=1',
        'dtstart' => now()->toDateString(),
        'active' => true,
    ]);

    Livewire::test('inspector')
        ->call('openInspector', 'checklist_template', $template->id)
        ->assertSet('checklist_recurrence_mode', 'one_off');
});

it('ticks an item lazily and creates a run for today', function () {
    $t = ChecklistTemplate::create([
        'name' => 'Evening', 'time_of_day' => 'evening', 'rrule' => 'FREQ=DAILY',
        'dtstart' => now()->toDateString(), 'active' => true,
    ]);
    $i1 = $t->items()->create(['label' => 'Plan tomorrow', 'active' => true, 'position' => 0]);
    $i2 = $t->items()->create(['label' => 'Clear inbox', 'active' => true, 'position' => 1]);

    expect(ChecklistRun::count())->toBe(0);

    Livewire::test('checklists-today')
        ->call('toggleItem', $t->id, $i1->id);

    $run = ChecklistRun::where('checklist_template_id', $t->id)->firstOrFail();
    expect($run->tickedIds())->toBe([$i1->id])
        ->and($run->completed_at)->toBeNull();

    // Ticking the last active item flips completed_at.
    Livewire::test('checklists-today')
        ->call('toggleItem', $t->id, $i2->id);

    $run->refresh();
    expect($run->tickedIds())->toBe([$i1->id, $i2->id])
        ->and($run->completed_at)->not->toBeNull();
});

it('clears completed_at when a ticked item is unticked', function () {
    $t = ChecklistTemplate::create([
        'name' => 'R', 'time_of_day' => 'anytime', 'rrule' => 'FREQ=DAILY',
        'dtstart' => now()->toDateString(), 'active' => true,
    ]);
    $i = $t->items()->create(['label' => 'Only step', 'active' => true, 'position' => 0]);

    Livewire::test('checklists-today')->call('toggleItem', $t->id, $i->id);
    $run = ChecklistRun::first();
    expect($run->completed_at)->not->toBeNull();

    Livewire::test('checklists-today')->call('toggleItem', $t->id, $i->id);
    $run->refresh();
    expect($run->completed_at)->toBeNull()
        ->and($run->tickedIds())->toBe([]);
});

it('markDone fills all active ids and sets completed_at', function () {
    $t = ChecklistTemplate::create([
        'name' => 'Quick', 'time_of_day' => 'anytime', 'rrule' => 'FREQ=DAILY',
        'dtstart' => now()->toDateString(), 'active' => true,
    ]);
    $ids = collect(['A', 'B', 'C'])->map(fn ($l, $p) => $t->items()->create(['label' => $l, 'active' => true, 'position' => $p])->id)->all();

    Livewire::test('checklists-today')->call('markDone', $t->id);

    $run = ChecklistRun::first();
    expect($run->tickedIds())->toEqualCanonicalizing($ids)
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->skipped_at)->toBeNull();
});

it('markSkipped records an empty run with skipped_at set', function () {
    $t = ChecklistTemplate::create([
        'name' => 'Skip me', 'time_of_day' => 'anytime', 'rrule' => 'FREQ=DAILY',
        'dtstart' => now()->toDateString(), 'active' => true,
    ]);
    $t->items()->create(['label' => 'X', 'active' => true, 'position' => 0]);

    Livewire::test('checklists-today')->call('markSkipped', $t->id);

    $run = ChecklistRun::first();
    expect($run->tickedIds())->toBe([])
        ->and($run->skipped_at)->not->toBeNull()
        ->and($run->completed_at)->toBeNull();
});

it('deletes items and runs when a template is deleted', function () {
    $t = ChecklistTemplate::create([
        'name' => 'Goodbye', 'time_of_day' => 'anytime', 'rrule' => 'FREQ=DAILY',
        'dtstart' => now()->toDateString(), 'active' => true,
    ]);
    $t->items()->create(['label' => 'X', 'active' => true, 'position' => 0]);
    ChecklistRun::create([
        'checklist_template_id' => $t->id,
        'run_date' => now()->toDateString(),
        'ticked_item_ids' => [],
    ]);

    Livewire::test('inspector')
        ->call('openInspector', 'checklist_template', $t->id)
        ->call('deleteRecord');

    expect(ChecklistTemplate::count())->toBe(0)
        ->and(ChecklistTemplateItem::count())->toBe(0)
        ->and(ChecklistRun::count())->toBe(0);
});

it('reorderItems rearranges the repeater to match the supplied keys', function () {
    $result = Livewire::test('inspector')
        ->call('openInspector', 'checklist_template')
        ->set('checklist_items', [
            'k1' => ['key' => 'k1', 'id' => null, 'label' => 'first', 'active' => true],
            'k2' => ['key' => 'k2', 'id' => null, 'label' => 'second', 'active' => true],
            'k3' => ['key' => 'k3', 'id' => null, 'label' => 'third', 'active' => true],
        ])
        ->call('reorderItems', ['k3', 'k1', 'k2']);

    // Keys survive the reorder; the map's insertion order (= visible order)
    // now matches the supplied sequence, with each row's label intact.
    expect(array_keys($result->get('checklist_items')))->toBe(['k3', 'k1', 'k2'])
        ->and($result->get('checklist_items')['k3']['label'])->toBe('third')
        ->and($result->get('checklist_items')['k1']['label'])->toBe('first')
        ->and($result->get('checklist_items')['k2']['label'])->toBe('second');
});

it('removes an item by its key without disturbing sibling labels', function () {
    // Regression: when the repeater was indexed, an earlier implementation
    // used array_splice on a numeric index, so after a reorder the user's
    // "delete row X" click targeted the wrong row. With key-keyed storage
    // the call is unambiguous.
    $result = Livewire::test('inspector')
        ->call('openInspector', 'checklist_template')
        ->set('checklist_items', [
            'k1' => ['key' => 'k1', 'id' => null, 'label' => 'one', 'active' => true],
            'k2' => ['key' => 'k2', 'id' => null, 'label' => 'two', 'active' => true],
            'k3' => ['key' => 'k3', 'id' => null, 'label' => 'three', 'active' => true],
        ])
        ->call('removeItem', 'k2');

    expect(array_keys($result->get('checklist_items')))->toBe(['k1', 'k3'])
        ->and($result->get('checklist_items')['k1']['label'])->toBe('one')
        ->and($result->get('checklist_items')['k3']['label'])->toBe('three');
});

it('renders the index and today pages without errors', function () {
    $this->get(route('life.checklists.index'))->assertOk();
    $this->get(route('life.checklists.today'))->assertOk();
});

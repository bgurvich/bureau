<?php

use App\Models\ChecklistRun;
use App\Models\ChecklistTemplate;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('Today tab surfaces only recurring (habit) templates', function () {
    authedInHousehold();
    ChecklistTemplate::create([
        'name' => 'Meditate', 'rrule' => 'FREQ=DAILY', 'dtstart' => now(), 'active' => true,
    ]);
    ChecklistTemplate::create([
        'name' => 'Shopping list', 'rrule' => 'FREQ=DAILY;COUNT=1', 'dtstart' => now(), 'active' => true,
    ]);
    ChecklistTemplate::create([
        'name' => 'Ad-hoc list', 'rrule' => null, 'dtstart' => now(), 'active' => true,
    ]);

    $c = Livewire::test('checklists-index')->set('tab', 'today');
    expect($c->get('habits')->pluck('name')->all())->toBe(['Meditate']);
});

it('toggles today\'s completion on a habit via toggleToday()', function () {
    authedInHousehold();
    $habit = ChecklistTemplate::create([
        'name' => 'Meditate', 'rrule' => 'FREQ=DAILY', 'dtstart' => now()->subDay(), 'active' => true,
    ]);

    $c = Livewire::test('checklists-index')->set('tab', 'today');
    $c->call('toggleToday', $habit->id);

    $run = ChecklistRun::where('checklist_template_id', $habit->id)
        ->where('run_date', CarbonImmutable::today()->toDateString())
        ->firstOrFail();
    expect($run->completed_at)->not->toBeNull();

    $c->call('toggleToday', $habit->id);
    expect($run->fresh()->completed_at)->toBeNull();
});

it('refuses to toggle a one-off checklist', function () {
    authedInHousehold();
    $oneOff = ChecklistTemplate::create([
        'name' => 'Pack for trip', 'rrule' => 'FREQ=DAILY;COUNT=1', 'dtstart' => now(), 'active' => true,
    ]);

    Livewire::test('checklists-index')->call('toggleToday', $oneOff->id);

    expect(ChecklistRun::count())->toBe(0);
});

it('streak increments with each consecutive completed day', function () {
    authedInHousehold();
    $habit = ChecklistTemplate::create([
        'name' => 'Meditate', 'rrule' => 'FREQ=DAILY', 'dtstart' => now()->subDays(10), 'active' => true,
    ]);

    $today = CarbonImmutable::today();
    foreach ([2, 1, 0] as $offset) {
        ChecklistRun::create([
            'checklist_template_id' => $habit->id,
            'run_date' => $today->subDays($offset)->toDateString(),
            'completed_at' => $today->subDays($offset),
        ]);
    }

    $c = Livewire::test('checklists-index')->set('tab', 'today');
    expect($c->get('streaks')[$habit->id])->toBe(3);
});

it('Model::isHabit() returns true for recurring templates and false for one-offs', function () {
    $h = new ChecklistTemplate(['rrule' => 'FREQ=DAILY']);
    $o = new ChecklistTemplate(['rrule' => 'FREQ=DAILY;COUNT=1']);
    $n = new ChecklistTemplate(['rrule' => null]);

    expect($h->isHabit())->toBeTrue()
        ->and($o->isHabit())->toBeFalse()
        ->and($o->isOneOff())->toBeTrue()
        ->and($n->isHabit())->toBeFalse();
});

it('inspector mount with asHabit=true pre-selects daily recurrence', function () {
    authedInHousehold();

    Livewire::test('inspector.checklist-template-form', ['asHabit' => true])
        ->assertSet('checklist_recurrence_mode', 'daily');
});

it('inspector mount with oneOff=true pre-selects one_off recurrence', function () {
    authedInHousehold();

    Livewire::test('inspector.checklist-template-form', ['oneOff' => true])
        ->assertSet('checklist_recurrence_mode', 'one_off');
});

it('History tab surfaces the 60-day heat grid data for habits', function () {
    authedInHousehold();
    $habit = ChecklistTemplate::create([
        'name' => 'Meditate', 'rrule' => 'FREQ=DAILY', 'dtstart' => now()->subDays(5), 'active' => true,
    ]);
    ChecklistRun::create([
        'checklist_template_id' => $habit->id,
        'run_date' => now()->subDays(2)->toDateString(),
        'completed_at' => now()->subDays(2),
    ]);

    $c = Livewire::test('checklists-index')->set('tab', 'history');
    $history = $c->get('history');

    expect($history)->toHaveKey($habit->id)
        ->and($history[$habit->id])->toHaveCount(1);
});

it('All tab filters by type chip (habit / one_off / all)', function () {
    authedInHousehold();
    ChecklistTemplate::create(['name' => 'Daily ritual', 'rrule' => 'FREQ=DAILY', 'dtstart' => now(), 'active' => true]);
    ChecklistTemplate::create(['name' => 'Shopping list', 'rrule' => 'FREQ=DAILY;COUNT=1', 'dtstart' => now(), 'active' => true]);

    $c = Livewire::test('checklists-index')->set('tab', 'all');
    expect($c->get('templates')->count())->toBe(2);

    $c->set('typeFilter', 'habit');
    expect($c->get('templates')->pluck('name')->all())->toBe(['Daily ritual']);

    $c->set('typeFilter', 'one_off');
    expect($c->get('templates')->pluck('name')->all())->toBe(['Shopping list']);
});

it('/life/checklists route renders for an authed user', function () {
    $user = authedInHousehold();
    $this->actingAs($user);
    $this->get(route('life.checklists.index'))->assertOk();
});

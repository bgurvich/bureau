<?php

use App\Models\ChecklistRun;
use App\Models\ChecklistTemplate;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('surfaces only templates with is_habit = true', function () {
    authedInHousehold();
    ChecklistTemplate::create([
        'name' => 'Meditate', 'rrule' => 'FREQ=DAILY', 'dtstart' => now(), 'active' => true, 'is_habit' => true,
    ]);
    ChecklistTemplate::create([
        'name' => 'Morning routine', 'rrule' => 'FREQ=DAILY', 'dtstart' => now(), 'active' => true, 'is_habit' => false,
    ]);

    $c = Livewire::test('habits-index');
    expect($c->get('habits')->pluck('name')->all())->toBe(['Meditate']);
});

it('toggles today\'s completion via the button', function () {
    authedInHousehold();
    $habit = ChecklistTemplate::create([
        'name' => 'Meditate', 'rrule' => 'FREQ=DAILY', 'dtstart' => now()->subDay(), 'active' => true, 'is_habit' => true,
    ]);

    $c = Livewire::test('habits-index');
    $c->call('toggleToday', $habit->id);

    $run = ChecklistRun::where('checklist_template_id', $habit->id)
        ->where('run_date', CarbonImmutable::today()->toDateString())
        ->firstOrFail();
    expect($run->completed_at)->not->toBeNull();

    // Second toggle un-completes
    $c->call('toggleToday', $habit->id);
    expect($run->fresh()->completed_at)->toBeNull();
});

it('refuses to toggle a non-habit template', function () {
    authedInHousehold();
    $ritual = ChecklistTemplate::create([
        'name' => 'Morning ritual', 'rrule' => 'FREQ=DAILY', 'dtstart' => now(), 'active' => true, 'is_habit' => false,
    ]);

    Livewire::test('habits-index')->call('toggleToday', $ritual->id);

    expect(ChecklistRun::count())->toBe(0);
});

it('streak increments with each consecutive completed day', function () {
    authedInHousehold();
    $habit = ChecklistTemplate::create([
        'name' => 'Meditate', 'rrule' => 'FREQ=DAILY', 'dtstart' => now()->subDays(10), 'active' => true, 'is_habit' => true,
    ]);

    $today = CarbonImmutable::today();
    foreach ([2, 1, 0] as $offset) {
        ChecklistRun::create([
            'checklist_template_id' => $habit->id,
            'run_date' => $today->subDays($offset)->toDateString(),
            'completed_at' => $today->subDays($offset),
        ]);
    }

    $c = Livewire::test('habits-index');
    expect($c->get('streaks')[$habit->id])->toBe(3);
});

it('inspector saves the is_habit flag', function () {
    authedInHousehold();

    Livewire::test('inspector.checklist-template-form')
        ->set('checklist_name', 'Walk 30 min')
        ->set('checklist_dtstart', now()->toDateString())
        ->set('checklist_recurrence_mode', 'daily')
        ->set('checklist_is_habit', true)
        ->call('save');

    $t = ChecklistTemplate::where('name', 'Walk 30 min')->firstOrFail();
    expect($t->is_habit)->toBeTrue();
});

it('inspector mount with asHabit=true pre-checks the habit flag', function () {
    authedInHousehold();

    Livewire::test('inspector.checklist-template-form', ['asHabit' => true])
        ->assertSet('checklist_is_habit', true);
});

it('inspector mount without asHabit keeps the habit flag off by default', function () {
    authedInHousehold();

    Livewire::test('inspector.checklist-template-form')
        ->assertSet('checklist_is_habit', false);
});

it('/habits route renders for an authed user', function () {
    $user = authedInHousehold();
    $this->actingAs($user);
    $this->get(route('life.habits'))->assertOk();
});

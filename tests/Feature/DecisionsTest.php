<?php

use App\Models\Decision;
use App\Models\Vehicle;
use Livewire\Livewire;

it('creates a decision via the inspector form', function () {
    authedInHousehold();

    Livewire::test('inspector.decision-form')
        ->set('decided_on', '2026-04-23')
        ->set('title', 'Switch to Namecheap')
        ->set('context', 'Renewal coming up, GoDaddy fees doubled')
        ->set('options_considered', "GoDaddy (stay)\nNamecheap\nCloudflare Registrar")
        ->set('chosen', 'Namecheap')
        ->set('rationale', 'Better price + WHOIS privacy included')
        ->set('follow_up_on', '2027-04-01')
        ->call('save');

    $d = Decision::firstOrFail();
    expect($d->title)->toBe('Switch to Namecheap')
        ->and($d->chosen)->toBe('Namecheap')
        ->and($d->rationale)->toContain('Better price')
        ->and($d->follow_up_on->toDateString())->toBe('2027-04-01')
        ->and($d->outcome)->toBeNull()
        ->and($d->user_id)->not->toBeNull();
});

it('rejects follow_up_on that precedes decided_on', function () {
    authedInHousehold();

    Livewire::test('inspector.decision-form')
        ->set('decided_on', '2026-04-23')
        ->set('title', 'Nope')
        ->set('follow_up_on', '2026-01-01')
        ->call('save')
        ->assertHasErrors(['follow_up_on']);

    expect(Decision::count())->toBe(0);
});

it('links a decision to a subject and the subject can reach the decision', function () {
    authedInHousehold();
    $vehicle = Vehicle::create(['kind' => 'car', 'model' => 'Civic']);

    Livewire::test('inspector.decision-form')
        ->set('decided_on', '2026-04-23')
        ->set('title', 'Keep the Civic, buy new tires')
        ->set('subject_refs', ['vehicle:'.$vehicle->id])
        ->call('save');

    $d = Decision::firstOrFail();
    expect($d->subjects()->pluck('id')->all())->toContain($vehicle->id);
});

it('status chips filter to pending, follow-up-due, and resolved buckets', function () {
    authedInHousehold();
    $today = now()->toDateString();
    $pastWeek = now()->subWeek()->toDateString();
    $nextMonth = now()->addMonth()->toDateString();

    // Pending (no outcome), no follow-up
    Decision::create(['decided_on' => $today, 'title' => 'Open call']);

    // Pending AND follow-up is due
    Decision::create(['decided_on' => $pastWeek, 'title' => 'Overdue follow-up', 'follow_up_on' => $pastWeek]);

    // Pending, follow-up in the future — not due yet
    Decision::create(['decided_on' => $today, 'title' => 'Future follow-up', 'follow_up_on' => $nextMonth]);

    // Resolved
    Decision::create(['decided_on' => $pastWeek, 'title' => 'Done call', 'outcome' => 'It worked out.']);

    $c = Livewire::test('decisions-index');
    expect($c->get('decisions')->count())->toBe(4);

    $c->set('statusFilter', 'pending');
    expect($c->get('decisions')->count())->toBe(3); // all three without outcome

    $c->set('statusFilter', 'awaiting_followup');
    $titles = $c->get('decisions')->pluck('title')->all();
    expect($titles)->toBe(['Overdue follow-up']);

    $c->set('statusFilter', 'resolved');
    expect($c->get('decisions')->pluck('title')->all())->toBe(['Done call']);
});

it('Attention radar counts decisions whose follow_up_on has passed and outcome is null', function () {
    authedInHousehold();
    $past = now()->subDays(5)->toDateString();
    $today = now()->toDateString();
    $future = now()->addDays(30)->toDateString();

    Decision::create(['decided_on' => $past, 'title' => 'Due — no outcome', 'follow_up_on' => $past]);        // ✓
    Decision::create(['decided_on' => $past, 'title' => 'Due today', 'follow_up_on' => $today]);              // ✓
    Decision::create(['decided_on' => $past, 'title' => 'Due future', 'follow_up_on' => $future]);            // ✗
    Decision::create(['decided_on' => $past, 'title' => 'Due but resolved', 'follow_up_on' => $past, 'outcome' => 'Worked.']); // ✗

    $c = Livewire::test('attention-radar');
    expect($c->get('decisionFollowUpsDue'))->toBe(2);
});

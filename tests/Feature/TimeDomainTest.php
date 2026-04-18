<?php

use App\Models\Project;
use App\Models\TimeEntry;

it('renders the Projects drill-down with totals', function () {
    $user = authedInHousehold();

    $project = Project::create([
        'user_id' => $user->id,
        'name' => 'Client Alpha',
        'slug' => 'client-alpha',
        'billable' => true,
        'hourly_rate' => 150,
        'hourly_rate_currency' => 'USD',
    ]);

    TimeEntry::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'started_at' => now()->subHours(3),
        'ended_at' => now(),
        'duration_seconds' => 7200,
        'activity_date' => now()->toDateString(),
        'billable' => true,
    ]);

    $this->get('/time/projects')
        ->assertOk()
        ->assertSee('Client Alpha')
        ->assertSee('Billable');
});

it('hides archived projects by default but shows them on toggle', function () {
    $user = authedInHousehold();

    Project::create([
        'user_id' => $user->id, 'name' => 'Active P', 'slug' => 'active-p', 'archived' => false,
    ]);
    Project::create([
        'user_id' => $user->id, 'name' => 'Old P', 'slug' => 'old-p', 'archived' => true,
    ]);

    $this->get('/time/projects')
        ->assertSee('Active P')
        ->assertDontSee('Old P');

    $this->get('/time/projects?archived=1')
        ->assertSee('Old P');
});

it('renders the Time entries drill-down with filter totals', function () {
    $user = authedInHousehold();

    $project = Project::create([
        'user_id' => $user->id, 'name' => 'Freelance', 'slug' => 'freelance',
        'billable' => true, 'hourly_rate' => 100, 'hourly_rate_currency' => 'USD',
    ]);

    TimeEntry::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'started_at' => now()->subDays(3)->setTime(9, 0),
        'ended_at' => now()->subDays(3)->setTime(11, 0),
        'duration_seconds' => 7200,
        'activity_date' => now()->subDays(3)->toDateString(),
        'description' => 'API refactor',
        'billable' => true,
    ]);

    $this->get('/time/entries')
        ->assertOk()
        ->assertSee('Freelance')
        ->assertSee('API refactor');
});

it('filters time entries by project', function () {
    $user = authedInHousehold();

    $p1 = Project::create(['user_id' => $user->id, 'name' => 'Alpha', 'slug' => 'alpha']);
    $p2 = Project::create(['user_id' => $user->id, 'name' => 'Beta', 'slug' => 'beta']);

    TimeEntry::create([
        'user_id' => $user->id, 'project_id' => $p1->id,
        'started_at' => now()->subDays(2), 'ended_at' => now()->subDays(2)->addHour(),
        'duration_seconds' => 3600, 'activity_date' => now()->subDays(2)->toDateString(),
        'description' => 'Alpha work',
    ]);
    TimeEntry::create([
        'user_id' => $user->id, 'project_id' => $p2->id,
        'started_at' => now()->subDays(2), 'ended_at' => now()->subDays(2)->addHour(),
        'duration_seconds' => 3600, 'activity_date' => now()->subDays(2)->toDateString(),
        'description' => 'Beta work',
    ]);

    $this->get('/time/entries?project='.$p1->id)
        ->assertSee('Alpha work')
        ->assertDontSee('Beta work');
});

<?php

use App\Mail\ReminderMail;
use App\Models\Appointment;
use App\Models\Document;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\UserNotificationPreference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

it('fires pending reminders via email and marks them fired', function () {
    Mail::fake();
    $user = authedInHousehold();

    $r = Reminder::create([
        'user_id' => $user->id,
        'title' => 'Pay rent',
        'body' => '$2,200 to landlord',
        'remind_at' => now()->subMinutes(5),
        'channel' => 'email',
        'state' => 'pending',
    ]);

    $this->artisan('reminders:fire')->assertSuccessful();

    Mail::assertSent(ReminderMail::class, fn ($mail) => $mail->hasTo($user->email));
    $fresh = $r->fresh();
    expect($fresh->state)->toBe('fired')
        ->and($fresh->fired_at)->not->toBeNull();
});

it('leaves future reminders alone', function () {
    Mail::fake();
    authedInHousehold();

    Reminder::create([
        'title' => 'Not yet',
        'remind_at' => now()->addHour(),
        'channel' => 'email',
        'state' => 'pending',
    ]);

    $this->artisan('reminders:fire')->assertSuccessful();

    Mail::assertNothingSent();
    expect(Reminder::where('state', 'pending')->count())->toBe(1);
});

it('honors an explicit user opt-out row', function () {
    Mail::fake();
    $user = authedInHousehold();

    $r = Reminder::create([
        'user_id' => $user->id,
        'remindable_type' => Task::class,
        'remindable_id' => 99,
        'title' => 'Task reminder',
        'remind_at' => now()->subMinute(),
        'channel' => 'email',
        'state' => 'pending',
    ]);

    UserNotificationPreference::create([
        'user_id' => $user->id,
        'household_id' => $r->household_id,
        'kind' => 'task_reminder',
        'channel' => 'email',
        'enabled' => false,
    ]);

    $this->artisan('reminders:fire')->assertSuccessful();

    Mail::assertNothingSent();
    // Skipped — stays pending for a later channel/pref change.
    expect($r->fresh()->state)->toBe('pending');
});

it('generates reminders from upcoming bills with rule.lead_days', function () {
    CarbonImmutable::setTestNow('2026-05-01 12:00:00');
    authedInHousehold();

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Internet',
        'amount' => -60, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=10',
        'dtstart' => '2026-04-10',
        'lead_days' => 4,
    ]);

    RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => '2026-05-12',
        'issued_on' => '2026-05-12',
        'amount' => -60,
        'currency' => 'USD',
        'status' => 'projected',
    ]);

    $this->artisan('reminders:generate')->assertSuccessful();

    $r = Reminder::firstOrFail();
    // due 2026-05-12 minus 4 lead days = 2026-05-08 at 08:00 local
    expect($r->remind_at->toDateString())->toBe('2026-05-08')
        ->and($r->title)->toContain('Internet')
        ->and($r->channel)->toBe('email');

    // Second run is idempotent — no duplicate.
    $this->artisan('reminders:generate')->assertSuccessful();
    expect(Reminder::count())->toBe(1);

    CarbonImmutable::setTestNow();
});

it('generates 30d and 7d reminders for expiring documents', function () {
    CarbonImmutable::setTestNow('2026-05-01 12:00:00');
    $user = authedInHousehold();

    Document::create([
        'kind' => 'passport', 'label' => 'US Passport',
        'holder_user_id' => $user->id,
        'expires_on' => '2026-06-15',
    ]);

    $this->artisan('reminders:generate')->assertSuccessful();

    $reminders = Reminder::where('remindable_type', Document::class)->get();
    expect($reminders->count())->toBe(2)
        ->and($reminders->pluck('remind_at')->map(fn ($d) => $d->toDateString())->sort()->values()->all())
        ->toBe(['2026-05-16', '2026-06-08']);

    CarbonImmutable::setTestNow();
});

it('generates an imminent-appointment reminder 1h ahead', function () {
    CarbonImmutable::setTestNow('2026-05-01 08:00:00');
    authedInHousehold();

    Appointment::create([
        'purpose' => 'Cleaning', 'starts_at' => '2026-05-01 14:30:00', 'state' => 'scheduled',
    ]);

    $this->artisan('reminders:generate')->assertSuccessful();

    $r = Reminder::where('remindable_type', Appointment::class)->first();
    expect($r)->not->toBeNull()
        ->and($r->remind_at->format('Y-m-d H:i'))->toBe('2026-05-01 13:30');

    CarbonImmutable::setTestNow();
});

<?php

use App\Mail\WeeklyDigestMail;
use App\Models\Account;
use App\Models\Contract;
use App\Models\Task;
use App\Models\Transaction;
use Illuminate\Support\Facades\Mail;

it('sends one weekly digest per user with expected counts', function () {
    Mail::fake();
    $user = authedInHousehold();

    $acc = Account::create(['name' => 'C', 'type' => 'checking', 'currency' => 'USD']);
    // New transactions this week
    Transaction::create([
        'account_id' => $acc->id, 'amount' => -25.00, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'coffee', 'status' => 'cleared',
    ]);
    Transaction::create([
        'account_id' => $acc->id, 'amount' => 100, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'refund', 'status' => 'cleared',
    ]);
    // Completed task
    Task::create(['title' => 'Done', 'state' => 'done', 'updated_at' => now()]);
    // Upcoming task
    Task::create(['title' => 'Next', 'state' => 'open', 'due_at' => now()->addDays(2)]);
    // Auto-renew with cancellation url in window
    Contract::create([
        'title' => 'Netflix', 'kind' => 'subscription', 'state' => 'active',
        'auto_renews' => true, 'ends_on' => now()->addDays(7),
        'cancellation_url' => 'https://netflix.com/cancel',
    ]);

    $this->artisan('digest:weekly')->assertSuccessful();

    Mail::assertSent(WeeklyDigestMail::class, function ($mail) use ($user) {
        $ok = $mail->hasTo($user->email);
        expect($mail->payload['new_transactions_count'])->toBe(2)
            ->and($mail->payload['completed_tasks_count'])->toBe(1)
            ->and($mail->payload['upcoming_tasks_count'])->toBe(1)
            ->and($mail->payload['expiring_contracts_count'])->toBe(1)
            ->and((float) $mail->payload['new_transactions_net'])->toBe(75.0);

        return $ok;
    });
});

it('dry-run does not actually send', function () {
    Mail::fake();
    authedInHousehold();

    $this->artisan('digest:weekly', ['--dry-run' => true])
        ->expectsOutputToContain('(dry run)')
        ->assertSuccessful();

    Mail::assertNothingSent();
});

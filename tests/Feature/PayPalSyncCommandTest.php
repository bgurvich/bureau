<?php

declare(strict_types=1);

it('rejects a malformed --from date with a clear error', function () {
    authedInHousehold();

    $this->artisan('paypal:sync', ['--from' => 'not-a-date'])
        ->expectsOutputToContain('Invalid --from date')
        ->assertExitCode(1);
});

it('rejects a future --from date', function () {
    authedInHousehold();

    $future = now()->addYear()->toDateString();
    $this->artisan('paypal:sync', ['--from' => $future])
        ->expectsOutputToContain('--from is in the future')
        ->assertExitCode(1);
});

it('accepts a valid --from date and announces the backfill', function () {
    authedInHousehold();

    // No PayPal integration exists → the household loop is a no-op after
    // the date-validation block, so we just check the pre-loop log line.
    $this->artisan('paypal:sync', ['--from' => '2023-01-01'])
        ->expectsOutputToContain('Historical backfill from 2023-01-01')
        ->assertExitCode(0);
});

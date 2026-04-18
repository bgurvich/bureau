<?php

use App\Models\Household;
use App\Models\User;
use App\Support\CurrentHousehold;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/*
 * DatabaseTransactions (not RefreshDatabase) is the default — it skips the
 * per-run `migrate:fresh` (~15s) by trusting that bureau_test's schema is
 * already current. After editing or adding a migration, run
 * `composer test:refresh` once to rebuild the schema.
 */
pest()->extend(TestCase::class)
    ->use(DatabaseTransactions::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * Create a household, attach a new owner user, set the tenant scope, and
 * acting-as the user. Returns the user so the caller can read its id/email.
 */
function authedInHousehold(string $householdName = 'Test', string $email = 'user@example.com'): User
{
    $household = Household::create(['name' => $householdName, 'default_currency' => 'USD']);
    $user = User::create([
        'name' => 'Tester',
        'email' => $email,
        'password' => bcrypt('secret-1234'),
        'default_household_id' => $household->id,
    ]);
    $household->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
    CurrentHousehold::set($household);
    test()->actingAs($user);

    return $user;
}

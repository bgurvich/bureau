<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\Contact;
use App\Models\RecurringRule;
use App\Models\Tag;
use App\Models\Transaction;
use App\Support\CurrentHousehold;
use Illuminate\Support\Facades\DB;

it('wipes transactions + derived state but keeps accounts / contacts / rules', function () {
    $user = authedInHousehold();
    $household = $user->defaultHousehold;

    $account = Account::create([
        'type' => 'checking', 'name' => 'Everyday',
        'currency' => 'USD', 'opening_balance' => 100,
    ]);
    $contact = Contact::create(['kind' => 'org', 'display_name' => 'Costco', 'is_vendor' => true]);
    $category = Category::create(['kind' => 'expense', 'slug' => 'food', 'name' => 'Food']);
    $rule = RecurringRule::create([
        'kind' => 'bill',
        'title' => 'Rent',
        'amount' => 2000,
        'currency' => 'USD',
        'account_id' => $account->id,
        'dtstart' => '2026-01-01',
        'rrule' => 'FREQ=MONTHLY',
        'active' => true,
    ]);

    $t = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-07-30',
        'amount' => -42,
        'currency' => 'USD',
        'description' => 'Costco run',
        'status' => 'cleared',
        'counterparty_contact_id' => $contact->id,
        'category_id' => $category->id,
    ]);

    // Attach a tag so we can verify the pivot cleanup.
    $tag = Tag::firstOrCreate(['slug' => 'groceries'], ['name' => 'groceries']);
    $t->tags()->attach($tag->id);

    $this->artisan('transactions:wipe', ['--force' => true])->assertExitCode(0);

    expect(Transaction::count())->toBe(0)
        ->and(DB::table('taggables')->where('taggable_id', $t->id)->where('taggable_type', Transaction::class)->count())->toBe(0)
        ->and(Account::count())->toBe(1)       // Account stays
        ->and(Contact::count())->toBe(1)       // Contact stays (match_patterns survive)
        ->and(Category::count())->toBeGreaterThanOrEqual(1) // Categories stay (system seeder + custom)
        ->and(RecurringRule::count())->toBe(1) // Rule stays, only projections wipe
        ->and(Tag::where('slug', 'groceries')->count())->toBe(1); // Tag name stays; only pivot deleted
});

it('dry-run prints counts without deleting', function () {
    $user = authedInHousehold();
    $account = Account::create([
        'type' => 'checking', 'name' => 'Everyday',
        'currency' => 'USD', 'opening_balance' => 0,
    ]);
    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-07-30',
        'amount' => -5,
        'currency' => 'USD',
        'description' => 'x',
        'status' => 'cleared',
    ]);

    $this->artisan('transactions:wipe', ['--dry-run' => true])->assertExitCode(0);

    expect(Transaction::count())->toBe(1);
});

it('restricts the wipe to a single household when --household is given', function () {
    $userA = authedInHousehold('Alpha', 'a@example.com');
    $householdA = $userA->defaultHousehold;

    $accountA = Account::create([
        'type' => 'checking', 'name' => 'A checking',
        'currency' => 'USD', 'opening_balance' => 0,
    ]);
    Transaction::create([
        'account_id' => $accountA->id,
        'occurred_on' => '2026-07-30',
        'amount' => -10,
        'currency' => 'USD',
        'description' => 'A',
        'status' => 'cleared',
    ]);

    // Second household with its own transaction.
    $userB = authedInHousehold('Beta', 'b@example.com');
    $householdB = $userB->defaultHousehold;
    $accountB = Account::create([
        'type' => 'checking', 'name' => 'B checking',
        'currency' => 'USD', 'opening_balance' => 0,
    ]);
    Transaction::create([
        'account_id' => $accountB->id,
        'occurred_on' => '2026-07-30',
        'amount' => -20,
        'currency' => 'USD',
        'description' => 'B',
        'status' => 'cleared',
    ]);

    // Wipe only household A.
    CurrentHousehold::set(null); // unscope so Transaction::count() sees all
    $this->artisan('transactions:wipe', ['--household' => $householdA->id, '--force' => true])->assertExitCode(0);

    // A's row is gone; B's survives.
    expect(Transaction::withoutGlobalScope('household')->where('household_id', $householdA->id)->count())->toBe(0)
        ->and(Transaction::withoutGlobalScope('household')->where('household_id', $householdB->id)->count())->toBe(1);
});

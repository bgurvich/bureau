<?php

use App\Models\Account;
use App\Models\Contract;
use App\Models\Document;
use App\Models\InventoryItem;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Task;
use App\Models\Transaction;

it('renders empty-state when nothing needs attention', function () {
    authedInHousehold();

    $this->get('/review')
        ->assertOk()
        ->assertSee('Inbox zero.');
});

it('groups actionable items across domains', function () {
    $user = authedInHousehold();

    Task::create([
        'title' => 'Fix leaky faucet',
        'due_at' => now()->subDays(2),
        'priority' => 2,
        'state' => 'open',
        'assigned_user_id' => $user->id,
    ]);

    $account = Account::create([
        'type' => 'checking', 'name' => 'Chase', 'currency' => 'USD', 'opening_balance' => 0,
    ]);
    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->subDays(14)->toDateString(),
        'amount' => -42.00,
        'currency' => 'USD',
        'description' => 'Stale pending — bank never confirmed',
        'status' => 'pending',
    ]);

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Rent',
        'amount' => -2200, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        'dtstart' => '2025-01-01',
        'account_id' => $account->id,
    ]);
    RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => now()->subDays(10)->toDateString(),
        'amount' => -2200, 'currency' => 'USD',
        'status' => 'overdue',
    ]);

    Document::create([
        'kind' => 'passport', 'label' => 'US Passport',
        'expires_on' => now()->addDays(20)->toDateString(),
        'holder_user_id' => $user->id,
    ]);

    Contract::create([
        'kind' => 'subscription', 'title' => 'Fiber internet',
        'state' => 'active',
        'starts_on' => now()->subYear()->toDateString(),
        'ends_on' => now()->addDays(25)->toDateString(),
    ]);

    InventoryItem::create(['name' => 'Unprocessed thing', 'category' => 'other']);

    $this->get('/review')
        ->assertOk()
        ->assertSee('Fix leaky faucet')
        ->assertSee('Stale pending')
        ->assertSee('Rent')
        ->assertSee('US Passport')
        ->assertSee('Fiber internet')
        ->assertSee('Unprocessed inventory');
});

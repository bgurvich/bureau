<?php

use App\Models\Account;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Household;
use App\Models\InsurancePolicy;
use App\Models\InventoryItem;
use App\Models\Note;
use App\Models\PeriodLock;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Tag;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Livewire\Livewire;

it('starts closed in picker mode', function () {
    authedInHousehold();

    Livewire::test('inspector')
        ->assertSet('open', false)
        ->assertSet('type', '');
});

it('opens the picker via event', function () {
    authedInHousehold();

    Livewire::test('inspector')
        ->call('openInspector')
        ->assertSet('open', true)
        ->assertSet('type', '');
});

it('creates a task', function () {
    authedInHousehold();

    Livewire::test('inspector')
        ->call('openInspector', 'task')
        ->set('title', 'Renew passport')
        ->set('priority', 1)
        ->set('state', 'open')
        ->call('save')
        ->assertSet('open', false);

    expect(Task::count())->toBe(1);
    $t = Task::first();
    expect($t->title)->toBe('Renew passport')
        ->and($t->priority)->toBe(1)
        ->and($t->state)->toBe('open');
});

it('edits an existing task', function () {
    authedInHousehold();

    $task = Task::create([
        'title' => 'Old', 'priority' => 3, 'state' => 'open',
    ]);

    Livewire::test('inspector')
        ->call('openInspector', 'task', $task->id)
        ->assertSet('title', 'Old')
        ->set('title', 'Updated title')
        ->set('state', 'done')
        ->call('save');

    $fresh = $task->fresh();
    expect($fresh->title)->toBe('Updated title')
        ->and($fresh->state)->toBe('done')
        ->and($fresh->completed_at)->not->toBeNull();
});

it('creates a note', function () {
    authedInHousehold();

    Livewire::test('inspector')
        ->call('openInspector', 'note')
        ->set('title', 'Standup')
        ->set('body', "things to do\nthings to say")
        ->set('pinned', true)
        ->call('save');

    $n = Note::first();
    expect($n)->not->toBeNull()
        ->and($n->title)->toBe('Standup')
        ->and($n->pinned)->toBeTrue();
});

it('creates a contact and splits email/phone lists', function () {
    authedInHousehold();

    Livewire::test('inspector.contact-form')
        ->set('kind', 'person')
        ->set('display_name', 'Alice Example')
        ->set('email', 'alice@example.com, alice.backup@example.com')
        ->set('phone', '555-0100; 555-0200')
        ->set('is_customer', true)
        ->set('tax_id', 'T-42')
        ->call('save');

    $c = Contact::first();
    expect($c)->not->toBeNull()
        ->and($c->display_name)->toBe('Alice Example')
        ->and($c->emails)->toBe(['alice@example.com', 'alice.backup@example.com'])
        ->and($c->phones)->toBe(['555-0100', '555-0200'])
        ->and($c->is_customer)->toBeTrue()
        ->and($c->tax_id)->toBe('T-42');
});

it('creates a transaction', function () {
    authedInHousehold();

    $account = Account::create([
        'type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0,
    ]);

    Livewire::test('inspector')
        ->call('openInspector', 'transaction')
        ->set('account_id', $account->id)
        ->set('occurred_on', '2026-04-10')
        ->set('amount', '-42.50')
        ->set('currency', 'USD')
        ->set('description', 'Test purchase')
        ->set('status', 'cleared')
        ->call('save');

    $t = Transaction::first();
    expect($t)->not->toBeNull()
        ->and((float) $t->amount)->toBe(-42.50)
        ->and($t->description)->toBe('Test purchase');
});

it('surfaces a period-lock error instead of throwing', function () {
    authedInHousehold();
    $account = Account::create([
        'type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0,
    ]);
    PeriodLock::create(['locked_through' => '2026-03-31', 'locked_at' => now()]);

    Livewire::test('inspector')
        ->call('openInspector', 'transaction')
        ->set('account_id', $account->id)
        ->set('occurred_on', '2026-03-15')
        ->set('amount', '-10')
        ->set('currency', 'USD')
        ->set('status', 'cleared')
        ->call('save')
        ->assertSet('open', true)  // stays open so the user sees the error
        ->assertSet('errorMessage', fn ($v) => is_string($v) && str_contains($v, '2026-03-31'));

    expect(Transaction::count())->toBe(0);
});

it('deletes a task', function () {
    authedInHousehold();
    $task = Task::create(['title' => 'Gone', 'priority' => 3, 'state' => 'open']);

    Livewire::test('inspector')
        ->call('openInspector', 'task', $task->id)
        ->call('deleteRecord');

    expect(Task::count())->toBe(0);
});

it('requires a title on task save', function () {
    authedInHousehold();

    Livewire::test('inspector')
        ->call('openInspector', 'task')
        ->call('save')
        ->assertHasErrors(['title']);
});

it('auto-creates a counterparty contact from a typed name', function () {
    authedInHousehold();

    Livewire::test('inspector')
        ->call('openInspector', 'transaction')
        ->call('createCounterparty', 'Brand New Vendor Inc')
        ->assertDispatched('ss-option-added',
            model: 'counterparty_contact_id',
            label: 'Brand New Vendor Inc',
        );

    $c = Contact::where('display_name', 'Brand New Vendor Inc')->first();
    expect($c)->not->toBeNull()
        ->and($c->kind)->toBe('org');
});

it('ignores empty names for counterparty auto-create', function () {
    authedInHousehold();

    Livewire::test('inspector')
        ->call('openInspector', 'transaction')
        ->call('createCounterparty', '   ');

    expect(Contact::count())->toBe(0);
});

it('dispatches ss-option-added for the exact picker that triggered the create', function () {
    // Regression: before fix, createCounterparty always dispatched with
    // model='counterparty_contact_id', so subscription/contract/inventory/etc.
    // searchable-selects (bound to their own *_id field) never received the
    // new option and appeared to hang after creation.
    authedInHousehold();

    Livewire::test('inspector.subscription-form')
        ->call('createCounterparty', 'Fresh Vendor LLC', 'subscription_counterparty_id')
        ->assertDispatched('ss-option-added',
            model: 'subscription_counterparty_id',
            label: 'Fresh Vendor LLC',
        )
        ->assertSet('subscription_counterparty_id', fn ($v) => $v !== null && $v > 0);
});

it('creates a one-off bill via the Bill form', function () {
    authedInHousehold();

    $account = Account::create([
        'type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0,
    ]);

    Livewire::test('inspector')
        ->call('openInspector', 'bill')
        ->set('bill_title', 'Emergency plumber')
        ->set('amount', '-350')
        ->set('currency', 'USD')
        ->set('account_id', $account->id)
        ->set('issued_on', '2026-04-10')
        ->set('due_on', '2026-04-24')
        ->set('is_recurring', false)
        ->set('autopay', false)
        ->call('save')
        ->assertSet('open', false);

    $rule = RecurringRule::first();
    expect($rule)->not->toBeNull()
        ->and($rule->title)->toBe('Emergency plumber')
        ->and($rule->rrule)->toContain('COUNT=1')
        ->and($rule->due_offset_days)->toBe(14)
        ->and((float) $rule->amount)->toBe(-350.0);

    $projection = RecurringProjection::first();
    expect($projection)->not->toBeNull()
        ->and($projection->issued_on->toDateString())->toBe('2026-04-10')
        ->and($projection->due_on->toDateString())->toBe('2026-04-24');
});

it('creates a recurring monthly bill via the Bill form', function () {
    authedInHousehold();
    $account = Account::create([
        'type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0,
    ]);

    Livewire::test('inspector')
        ->call('openInspector', 'bill')
        ->set('bill_title', 'Rent')
        ->set('amount', '-2200')
        ->set('currency', 'USD')
        ->set('account_id', $account->id)
        ->set('issued_on', '2026-04-01')
        ->set('due_on', '2026-04-05')
        ->set('is_recurring', true)
        ->set('frequency', 'monthly')
        ->set('autopay', true)
        ->call('save');

    $rule = RecurringRule::first();
    expect($rule->rrule)->toBe('FREQ=MONTHLY;BYMONTHDAY=1')
        ->and($rule->autopay)->toBeTrue()
        ->and($rule->due_offset_days)->toBe(4);
});

it('mark-paid pre-fills the Transaction form from the projection', function () {
    authedInHousehold();

    $account = Account::create([
        'type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0,
    ]);
    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Internet',
        'amount' => -60, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=10',
        'dtstart' => '2026-04-10',
        'account_id' => $account->id,
    ]);
    $projection = RecurringProjection::create([
        'rule_id' => $rule->id,
        'issued_on' => '2026-04-10',
        'due_on' => '2026-04-10',
        'amount' => -60,
        'currency' => 'USD',
        'status' => 'projected',
    ]);

    Livewire::test('inspector')
        ->call('markPaid', $projection->id)
        ->assertSet('open', true)
        ->assertSet('type', 'transaction')
        ->assertSet('amount', '-60.0000')
        ->assertSet('account_id', $account->id)
        ->assertSet('description', 'Internet')
        ->assertSet('status', 'cleared');
});

it('creates an insurance policy with a covered vehicle', function () {
    authedInHousehold();

    $vehicle = Vehicle::create(['kind' => 'car', 'make' => 'Honda', 'model' => 'Civic', 'year' => 2020]);
    $carrier = Contact::create(['kind' => 'org', 'display_name' => 'Acme Mutual']);

    Livewire::test('inspector')
        ->call('openInspector', 'insurance')
        ->set('insurance_title', 'Civic auto policy')
        ->set('insurance_coverage_kind', 'auto')
        ->set('insurance_policy_number', 'AUTO-42')
        ->set('insurance_carrier_id', $carrier->id)
        ->set('insurance_premium_amount', '120')
        ->set('insurance_premium_currency', 'USD')
        ->set('insurance_premium_cadence', 'monthly')
        ->set('insurance_coverage_amount', '50000')
        ->set('insurance_deductible_amount', '500')
        ->set('insurance_subject', 'vehicle:'.$vehicle->id)
        ->call('save')
        ->assertSet('open', false);

    $contract = Contract::firstWhere('title', 'Civic auto policy');
    expect($contract)->not->toBeNull()
        ->and($contract->kind)->toBe('insurance')
        ->and($contract->monthly_cost_amount)->toEqual('120.0000');

    $policy = InsurancePolicy::firstWhere('contract_id', $contract->id);
    expect($policy)->not->toBeNull()
        ->and($policy->coverage_kind)->toBe('auto')
        ->and($policy->policy_number)->toBe('AUTO-42')
        ->and($policy->carrier_contact_id)->toBe($carrier->id);

    $subject = $policy->subjects()->first();
    expect($subject)->not->toBeNull()
        ->and($subject->subject_type)->toBe(Vehicle::class)
        ->and($subject->subject_id)->toBe($vehicle->id)
        ->and($subject->role)->toBe('covered');
});

it('edits an existing insurance policy and reassigns its subject', function () {
    authedInHousehold();

    $vehicleA = Vehicle::create(['kind' => 'car', 'make' => 'Honda', 'model' => 'Civic']);
    $vehicleB = Vehicle::create(['kind' => 'car', 'make' => 'Toyota', 'model' => 'Camry']);

    $contract = Contract::create(['kind' => 'insurance', 'title' => 'Auto', 'state' => 'active']);
    $policy = InsurancePolicy::create([
        'contract_id' => $contract->id,
        'coverage_kind' => 'auto',
        'policy_number' => 'OLD',
        'premium_amount' => 100,
        'premium_currency' => 'USD',
        'premium_cadence' => 'monthly',
    ]);
    $policy->subjects()->create([
        'subject_type' => Vehicle::class,
        'subject_id' => $vehicleA->id,
        'role' => 'covered',
    ]);

    Livewire::test('inspector')
        ->call('openInspector', 'insurance', $contract->id)
        ->assertSet('insurance_policy_number', 'OLD')
        ->assertSet('insurance_subject', 'vehicle:'.$vehicleA->id)
        ->set('insurance_policy_number', 'NEW')
        ->set('insurance_subject', 'vehicle:'.$vehicleB->id)
        ->call('save')
        ->assertSet('open', false);

    $policy->refresh()->load('subjects');
    expect($policy->policy_number)->toBe('NEW')
        ->and($policy->subjects)->toHaveCount(1)
        ->and($policy->subjects->first()->subject_id)->toBe($vehicleB->id);
});

it('attaches tags entered as space-separated names with optional # prefix', function () {
    authedInHousehold();

    Livewire::test('inspector')
        ->call('openInspector', 'note')
        ->set('title', 'Filing cabinet')
        ->set('body', 'the body')
        ->set('tag_list', '#tax-2026 #home urgent')
        ->call('save');

    $note = Note::first();
    expect($note)->not->toBeNull();
    $names = $note->tags()->pluck('name')->sort()->values()->all();
    expect($names)->toBe(['home', 'tax-2026', 'urgent']);
});

it('reuses existing tags on a second record instead of creating duplicates', function () {
    authedInHousehold();

    Livewire::test('inspector')
        ->call('openInspector', 'note')
        ->set('title', 'First')
        ->set('body', '…')
        ->set('tag_list', 'tax-2026')
        ->call('save');
    Livewire::test('inspector')
        ->call('openInspector', 'note')
        ->set('title', 'Second')
        ->set('body', '…')
        ->set('tag_list', 'tax-2026 urgent')
        ->call('save');

    expect(Tag::where('slug', 'tax-2026')->count())->toBe(1);
    expect(Tag::count())->toBe(2);
});

it('transfers ownership via the Admin picker', function () {
    $me = authedInHousehold();

    $spouse = User::create([
        'name' => 'Spouse',
        'email' => 'spouse@example.com',
        'password' => bcrypt('secret-1234'),
        'default_household_id' => $me->default_household_id,
    ]);
    Household::find($me->default_household_id)
        ->users()->attach($spouse->id, ['role' => 'member', 'joined_at' => now()]);

    $account = Account::create([
        'type' => 'checking', 'name' => 'Chase', 'currency' => 'USD',
        'opening_balance' => 500, 'user_id' => $me->id,
    ]);

    Livewire::test('inspector.account-form', ['id' => $account->id])
        ->assertSet('admin_owner_id', $me->id)
        ->set('admin_owner_id', $spouse->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->user_id)->toBe($spouse->id);
});

it('releases ownership to Shared when the picker is cleared', function () {
    $me = authedInHousehold();

    $account = Account::create([
        'type' => 'checking', 'name' => 'Joint Checking', 'currency' => 'USD',
        'opening_balance' => 100, 'user_id' => $me->id,
    ]);

    Livewire::test('inspector.account-form', ['id' => $account->id])
        ->set('admin_owner_id', null)
        ->call('save');

    expect($account->fresh()->user_id)->toBeNull();
});

it('creates a gift-card account with vendor and expiry', function () {
    authedInHousehold();
    $vendor = Contact::create(['kind' => 'org', 'display_name' => 'Amazon']);

    Livewire::test('inspector.account-form')
        ->set('account_name', 'Amazon GC')
        ->set('account_type', 'gift_card')
        ->set('account_currency', 'USD')
        ->set('account_opening_balance', '50.00')
        ->set('account_vendor_id', $vendor->id)
        ->set('account_expires_on', '2027-01-15')
        ->call('save')
        ->assertHasNoErrors();

    $acct = Account::firstWhere('name', 'Amazon GC');
    expect($acct)->not->toBeNull()
        ->and($acct->type)->toBe('gift_card')
        ->and((float) $acct->opening_balance)->toEqual(50.0)
        ->and($acct->vendor_contact_id)->toBe($vendor->id)
        ->and($acct->expires_on->toDateString())->toBe('2027-01-15');
});

it('saves a subscription contract with a trial end date', function () {
    authedInHousehold();
    $vendor = Contact::create(['kind' => 'org', 'display_name' => 'Equinox Gym']);

    Livewire::test('inspector')
        ->call('openInspector', 'contract')
        ->set('contract_kind', 'subscription')
        ->set('contract_title', 'Gym membership')
        ->set('contract_state', 'active')
        ->set('contract_counterparty_id', $vendor->id)
        ->set('contract_trial_ends_on', now()->addDays(14)->toDateString())
        ->call('save')
        ->assertSet('open', false);

    $contract = Contract::firstWhere('title', 'Gym membership');
    expect($contract)->not->toBeNull()
        ->and($contract->trial_ends_on)->not->toBeNull()
        ->and($contract->trial_ends_on->toDateString())->toBe(now()->addDays(14)->toDateString());
});

it('saves an inventory item with quantity and container', function () {
    authedInHousehold();

    Livewire::test('inspector')
        ->call('openInspector', 'inventory')
        ->set('inventory_name', 'Candles')
        ->set('inventory_quantity', 20)
        ->set('inventory_room', 'Linen closet')
        ->set('inventory_container', 'Closet 2')
        ->call('save')
        ->assertSet('open', false);

    $item = InventoryItem::firstWhere('name', 'Candles');
    expect($item)->not->toBeNull()
        ->and((int) $item->quantity)->toBe(20)
        ->and($item->room)->toBe('Linen closet')
        ->and($item->container)->toBe('Closet 2');
});

it('saves an inventory item with vendor, order #, and return-by date', function () {
    authedInHousehold();
    $vendor = Contact::create(['kind' => 'org', 'display_name' => 'Apple Store']);

    Livewire::test('inspector')
        ->call('openInspector', 'inventory')
        ->set('inventory_name', 'MacBook Pro')
        ->set('inventory_category', 'electronic')
        ->set('inventory_vendor_id', $vendor->id)
        ->set('inventory_order_number', 'W0123456789')
        ->set('inventory_return_by', '2026-05-17')
        ->call('save')
        ->assertSet('open', false);

    $item = InventoryItem::firstWhere('name', 'MacBook Pro');
    expect($item)->not->toBeNull()
        ->and($item->purchased_from_contact_id)->toBe($vendor->id)
        ->and($item->order_number)->toBe('W0123456789')
        ->and($item->return_by->toDateString())->toBe('2026-05-17');
});

it('records a vehicle sale with disposition + sale_amount + buyer', function () {
    authedInHousehold();
    $buyer = Contact::create(['kind' => 'person', 'display_name' => 'Alice Buyer']);
    $vehicle = Vehicle::create([
        'kind' => 'car', 'make' => 'Honda', 'model' => 'Civic',
        'purchase_price' => 20000, 'purchase_currency' => 'USD',
    ]);

    Livewire::test('inspector.vehicle-form', ['id' => $vehicle->id])
        ->set('vehicle_disposed_on', '2026-04-01')
        ->set('disposition', 'sold')
        ->set('sale_amount', '14500')
        ->set('sale_currency', 'USD')
        ->set('buyer_contact_id', $buyer->id)
        ->call('save');

    $fresh = $vehicle->fresh();
    expect($fresh->disposition)->toBe('sold')
        ->and($fresh->disposed_on->toDateString())->toBe('2026-04-01')
        ->and((float) $fresh->sale_amount)->toEqual(14500.0)
        ->and($fresh->buyer_contact_id)->toBe($buyer->id);
});

it('saves a vehicle with VIN and registration fields', function () {
    authedInHousehold();

    Livewire::test('inspector.vehicle-form')
        ->set('vehicle_kind', 'car')
        ->set('vehicle_make', 'Honda')
        ->set('vehicle_model', 'Civic')
        ->set('vehicle_year', 2020)
        ->set('vehicle_vin', '1hgbh41jxmn109186')
        ->set('vehicle_registration_expires_on', '2027-03-15')
        ->set('vehicle_registration_fee_amount', '85')
        ->set('vehicle_registration_fee_currency', 'USD')
        ->call('save')
        ->assertHasNoErrors();

    $v = Vehicle::firstWhere('make', 'Honda');
    expect($v)->not->toBeNull()
        ->and($v->vin)->toBe('1HGBH41JXMN109186')
        ->and($v->registration_expires_on->toDateString())->toBe('2027-03-15')
        ->and((float) $v->registration_fee_amount)->toEqual(85.0)
        ->and($v->registration_fee_currency)->toBe('USD');
});

it('mark-paid save auto-matches the projection', function () {
    authedInHousehold();

    $account = Account::create([
        'type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0,
    ]);
    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Phone',
        'amount' => -40, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY',
        'dtstart' => '2026-04-05',
        'account_id' => $account->id,
    ]);
    $projection = RecurringProjection::create([
        'rule_id' => $rule->id,
        'issued_on' => '2026-04-05',
        'due_on' => '2026-04-05',
        'amount' => -40,
        'currency' => 'USD',
        'status' => 'overdue',
    ]);

    Livewire::test('inspector')
        ->call('markPaid', $projection->id)
        ->call('save');

    $fresh = $projection->fresh();
    expect($fresh->status)->toBe('matched')
        ->and($fresh->matched_transaction_id)->not->toBeNull();
});
